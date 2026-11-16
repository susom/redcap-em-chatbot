# SecureChatAI + REDCapAgentRecordTools — technical summary

Read from source on 2026-09-01, from the checkouts in
`www/modules-local/`. Both repos already ship their own READMEs; this document
is the cross-module view plus the parts that are only visible in the code.

| Module | Checkout | Drift vs `origin/master` |
|---|---|---|
| SecureChatAI | `secure_chat_ai_v9.9.9` @ `0d6f29d` (2026-08-25) | 5 behind, 1 ahead (local commit not pushed) |
| REDCapAgentRecordTools | `redcap_agent_record_tools_v9.9.9` @ `3fd1ded` (2026-06-12) | **24 behind** — the tool list itself differs, see §2.3 |

Both are framework-version 14 EMs. Neither renders chat UI — that is the
chatbot EM's job.

---

## 1. SecureChatAI — the gateway

~2,750 lines in `SecureChatAI.php` plus 14 classes and 7 model adapters. It is
the only component that talks to a model provider.

### 1.1 Entry points

| Caller | Path |
|---|---|
| Another EM (the normal case) | `getModuleInstance('secure_chat_ai')->callAI($model, $params, $project_id, $username)` — in-process PHP, no HTTP |
| REDCap API (`content=externalModule`) | `redcap_module_api()` → **only `callAI` and `messages`**, the two declared `api-actions` (`access: ["auth"]`) |
| EM-to-EM only | `redcap_module_api('getSession', …)` — implemented in the router but **not declared** in `config.json`, so it is not reachable over the REDCap API |

`messages` is an **Anthropic Messages API–compatible endpoint**: it accepts
`{model, messages, system, max_tokens, temperature, top_p, stop}`, maps public
Claude/GPT model names onto local aliases, and returns a Claude-shaped response
(`content[0].text`, `stop_reason`, `usage.input_tokens/output_tokens`). That is
what lets Claude Code / Ollama-compatible clients point at REDCap.

### 1.2 Model routing

Models come from the `api-settings` sub-setting list, each row supplying
`model-alias → {api-url, api-token, api-key-var, api-input-var, model-id}`, one
of which may be flagged default. Dispatch in `callLLMOnce()` is by alias
pattern, not by provider config:

- `*embed*` / `ada-002` → `GPTModelRequest` (embeddings pass straight through
  `normalizeResponse` and `sanitizeOutputForUI` untouched)
- `*whisper*` → `WhisperModelRequest` (speech-to-text)
- `*tts*` → `GPT4oMiniTTSModelRequest`
- `claude*` / `*anthropic*` → `ClaudeModelRequest` (Bedrock body shape)
- everything else → `GenericModelRequest` (OpenAI-compatible: Azure AI
  Foundry, Vertex, Llama…)

Request shaping happens in `filterDefaultParamsForModel()`: it merges system
defaults, strips `reasoning*` for models that don't take it, strips
`json_schema` for models that can't do structured output, rebuilds a *strict*
param set for reasoning models (`o1/o3/o3-mini/o4-mini/gpt-5*` → only `model`,
`messages`, `max_completion_tokens`, plus optional `reasoning_effort` and
`json_schema`), and drops internal keys (`session_id`, `agent_mode`,
`project_id`, `log_turn`) that are not part of any provider contract.

`computeDynamicMaxTokens()` then sizes the output budget per call:
`context − promptTokens − buffer`, capped at the model's `output_max`, floored
at 1024, written to `max_tokens` or `max_completion_tokens` depending on the
model family. Prompt tokens are counted with tiktoken
(`Yethee\Tiktoken\EncoderProvider`), falling back to `chars / 4` for models
outside the registry. **Caveat — it does not measure the real prompt**: see
§1.8.

`normalizeResponse()` flattens every provider shape to
`{content, role, model, usage{prompt_tokens, completion_tokens, total_tokens}}`.
If the content parses as JSON it is also exposed as `structured_output`, and
`preserve_structure` is set **unless** the JSON looks like the agent envelope
(`final_answer` / `tool_call` / `thinking`).

`sanitizeOutputForUI()` is the last gate: it unwraps agent envelopes (including
a regex backstop for truncated JSON), strips ```` ```json ```` fences, and maps
error types to human sentences (`TIMEOUT`, `MAX_TOOLS_EXCEEDED`,
`TOOL_LOOP_DETECTED`, …) so the UI never receives a raw error array.
Embeddings, TTS and Whisper responses are passed through unchanged.

### 1.3 Two execution paths

`callAI()` retries twice on exception, and branches on
`params.agent_mode && system enable_agent_mode`:

- **single call** → `callLLMOnce()`
- **agent mode** → `runAgentLoop()`

Both then run `sanitizeOutputForUI()` and `logConversationTurn()`.

`compactMessagesIfNeeded()` runs first but is opt-in: it returns immediately
unless the caller passes `compact_if_needed`. When armed, it summarises
everything older than `compact_keep_recent` (default 6) once the prompt exceeds
80% of the model's context window. **The chatbot EM does not set that flag** —
the compaction that actually happens today is client-side, in the React app.

### 1.4 The agent loop

```
runAgentLoop()
  ├─ force a schema-capable model (falls back to the first configured one of
  │  gpt-4-1 / gpt-5* / o1 / o3 / o3-mini / o4-mini; throws if none exist)
  ├─ loadToolsForProject(pid)          → tool definitions (§1.5)
  ├─ injectAgentSystemContext()        → PREPENDS a 2nd system message:
  │     router prompt + "TOOLS AVAILABLE:" text catalog (+ spawnAgent if depth allows)
  ├─ unset($params['tools'], $params['tool_choice'])   ← native tool calling is OFF
  ├─ set json_schema "agent_response" {tool_call | final_answer | thinking}
  └─ loop up to max_steps:
        callLLMOnce → decode JSON (regex rescue, then plain-text fallback)
          ├─ tool_call    → executeToolCall() → cap result → append as a USER
          │                 message "TOOL RESULT [name]: {json}" → continue
          └─ final_answer → return {content, tools_used[]}
```

Safety rails, read as system settings with these defaults: `agent_max_steps` 8,
`agent_max_tools_per_run` 15, `agent_timeout_seconds` 120,
`agent_max_subagent_depth` 1, and `agent_max_tool_result_chars` 8000 — that last
one is read by the code but **not declared in this checkout's `config.json`**,
so it cannot be changed from the settings screen here (upstream `26eb4ab`
declares it). Sub-agents get half the steps/tools/timeout. Loop detection aborts
when one `tool:args` signature appears 3+ times in the last 5 calls.

`capToolResultSize()` is shape-aware: it drops list items or trailing object
keys and appends a `_truncated` marker — **which is why response key order
matters** for any tool that returns both a summary and bulk rows.

`spawnAgent` is a built-in tool (not from any EM) that runs a nested loop with
its own tool subset and returns a final string to the parent.

### 1.5 Tool discovery and the execution pipeline

Discovery is *additive*: system setting `agent_tool_em_prefixes` ∪ project
setting `project_agent_tool_em_prefixes`. For each prefix it reads that EM's
`tools.json` manifest and normalises entries to
`{name, description, endpoint: module_api, module:{prefix, action}, parameters, readOnly, destructive}`.
No manifest, no tools — an EM being *enabled* is not sufficient.

Execution goes through `ToolPipeline`, which never throws:

| Phase | What happens |
|---|---|
| 1 Lookup | resolve the name; unknown tool is **non-fatal** since 2026-08-24 — it is fed back as `status: unavailable` so the turn survives |
| 2 Parse | required keys from the tool's JSON Schema |
| 3 Validate | tool-specific (`JsonConfigToolAdapter` returns null) |
| 4 Pre-hooks | any `deny` aborts before execution |
| 5 Execute | `module_api` → EM-to-EM `redcap_module_api()`; `redcap_api` → Guzzle POST to the REDCap API with `agent_tools_project_api_key` |
| 6 Post-hooks | logging/metrics; failures swallowed |

Hook denials and missing-parameter errors are deliberately returned with
`error: false` so the agent can explain or ask, rather than the turn dying.

**`ProjectAccessPreHook` is always on and not configurable.** If a tool payload
carries `pid`, it allows the session project, denies when there is no username
(fail-closed), and otherwise checks `redcap_user_rights`. Note the corollary:
**a tool with no `pid` in its payload is not gated by it** — that is exactly the
hole `projects.search` falls through (§2.4).

Everything else is pluggable: `pre_tool_use_hooks` / `post_tool_use_hooks`
(system) and `project_pre_tool_use_hooks` / `project_post_tool_use_hooks`
(project) take comma-separated class names implementing `PreToolUseHook` /
`PostToolUseHook`. `disable_phi_field_hook` is a project-level opt-out matched
by class basename.

### 1.6 Persistence, sessions, usage

`ASEMLO` (Abstract Simple EM Log Object) → `SecureChatLog` stores JSON rows in
`redcap_external_modules_log` under record names `SecureChatLog`,
`SecureChatLogError`, `CappyChatTurn`, `CappyChatTurnError`.

- `logConversationTurn()` writes **one clean row per turn** (user message,
  assistant answer, model, `tools_used`, `usage`) keyed by `session_id`;
  skipped when there is no `session_id`, when `log_turn === false`, or when
  both sides are empty.
- `rehydrateProjectSession()` prefers those turn rows. Session ids are
  client-generated and guessable, so when a `$username` is supplied it keeps
  only rows whose payload `username` matches (checked in PHP after the pid
  check, not in SQL). Legacy `SecureChatLog` rows carry no username, and the
  code is careful about it: the pre-deploy backfill via
  `legacyReconstructSession()` runs **only on unscoped requests**, and a
  user-scoped request with no turn rows returns an empty session rather than
  falling back. Unscoped calls — i.e. the EM-to-EM `getSession` action, which
  passes no username — see everything logged under that `session_id` + pid.
- `getProjectSecureChatLogs()` deliberately bypasses the EM framework with raw
  SQL, because logs written from system context have a NULL `project_id`
  column even though the JSON payload has the right pid.
- Pages: **AI Usage Dashboard** (project), **Log Visualization** and **model
  smoke tests** (control center).

### 1.7 PHI posture — precise version

The chat paths are careful: `emDebug` calls log counts, roles, lengths, token
usage and shapes, and `summarizeResponseForDebug()` exists specifically to keep
response bodies out of the log. `callAI()`'s catch block comments that
`$params` must never be logged directly.

Two documented exceptions write the prompt itself, and a summary that omits
them would be wrong:

1. `BaseModelRequest::logFullRequest()` — emLogs the **full outgoing request
   body** (API key masked) whenever `$GLOBALS['is_development_server']` is
   truthy. On `redcap.local` that flag is currently **1**, so prompts are being
   logged in full on that instance.
2. `BaseModelRequest::captureRequest()` — writes the verbatim body plus a
   runnable `curl` script to disk. Off unless armed twice: point
   `SECURECHAT_CAPTURE_DIR` (env) or the undeclared `securechat-capture-dir`
   system setting at a path, *and* create that directory writable. Covers six
   of seven adapters (TTS runs its own curl and is not captured).

### 1.8 Things declared but not wired

- `project-monthly-token-limit` and `project-monthly-cost-limit` appear only in
  `config.json` and the README — **no PHP reads them**. They are documented as
  "usage caps per project" but nothing enforces them today.
- `modelSupportsNativeTools()` and `toOpenAIToolsShape()` return `false` / `[]`
  with the real bodies commented out — native function calling is intentionally
  disabled for the agent rollout.
- **`computeDynamicMaxTokens()` is fed the wrong string.** `callLLMOnce():1172`
  passes `$fullPrompt ?? $filteredParams['messages'][0]['content'] ?? ''`, and
  `$fullPrompt` is never assigned anywhere in the file — `??` silently swallows
  the undefined variable, so the budget is sized from **the first message
  only**. In agent mode that is the router prompt + tool catalog (~540 tokens)
  even when the real prompt is 25K. It rarely bites because
  `min(available, output_max)` clamps the result anyway, but the "leave room
  for the prompt" logic is not actually doing that. A one-line fix
  (`$this->messagesToText($filteredParams['messages'] ?? [])`) would make the
  computation match its intent.

---

## 2. REDCapAgentRecordTools — the data layer

### 2.1 Shape

Deliberately small: no hooks, no pages, no settings beyond debug logging. One
router (`redcap_module_api($action, $payload)`) switching to one method per
tool, plus `tools.json` (the manifest SecureChatAI reads) and one pre-hook class
it contributes to SecureChatAI's pipeline. Every tool is also exposed as a
REDCap `api-action` with `access: ["auth"]`.

Two conventions run through it: everything goes through the `\REDCap::` facade
(`getDataDictionary`, `getInstrumentNames`, `getData`, `getSurveyLink`,
`evaluateLogic`, `saveData`) so REDCap's own rights and validation apply; and
**errors are returned as data** (`{error: true, message: …}`), never thrown
across the EM boundary.

### 2.2 Tools in this checkout (`3fd1ded`)

| Tool | Action | Required | REDCap call | Notes |
|---|---|---|---|---|
| `projects.search` | `projects_search` | `query` | raw SQL on `redcap_projects` | **no user filtering** — `// TODO` in code |
| `projects.getMetadata` | `projects_getMetadata` | `pid` | `getDataDictionary` | returns 8 fields per item incl. choices, validation, branching |
| `projects.getInstruments` | `projects_getInstruments` | `pid` | `getInstrumentNames` | |
| `records.get` | `records_get` | `pid`, `record_id` | `getData` | optional `fields`, `events` |
| `records.search` | `records_search` | `pid` | `getData` with `filterLogic` | returns `{pid, filter, record_count, records}` — raw rows, no cache/preview |
| `records.evaluateLogic` | `records_evaluateLogic` | `pid`, `record_id`, `logic` | `evaluateLogic` | boolean + raw result |
| `survey.getLink` | `survey_getLink` | `pid`, `record_id`, `instrument` | `getSurveyLink` | optional event/instance |
| `records.save` | `records_save` | `pid`, `data` | `saveData('json')` | `readOnly: false`, `destructive: true`; normalises flat/nested payloads; `overwrite` opt-in |

`PhiFieldPreHook` (registered into SecureChatAI, not self-registering): for
`records.get` / `records.search` / `records.save`, if the model alias does not
contain `gemini` (the only BAA-covered family at Stanford), it denies access to
fields flagged `identifier = y` in the data dictionary. Asking for *all* fields
is denied outright with the list of safe field names, so the agent can retry
precisely.

### 2.3 What `origin/master` changes (24 commits ahead)

This matters because the tool list is not the same:

- **`records.save` disabled** (`c3aab17` "make record tools read-only") and
  **`projects.search` removed** (`6b5c94c`, explicitly "no user-rights
  filter"). Master's manifest is 8 tools, all `readOnly: true`.
- **Added**: `records.count`, `records.listIds`.
- **Server-side reference cache** for large results — session-based first,
  then moved to a file-backed store (`60b9f25`) — enabling `reference` +
  `offset`/`limit` paging, `append_to` unions and in-memory narrowing.
- **Server-built previews**: `preview_markdown` with choice labels resolved
  from the dictionary (both `|` and `\n` enum separators), checkbox fields
  joined as labels, cells capped at 120 chars.
- **`records.aggregate`** exists in code but is hidden from the manifest.
- File grows 549 → ~2,900 lines.

Anything that depends on previews, paging or the recordset cache — including
the `paging` passthrough SecureChatAI already implements for `records.search`
(it looks for `reference` + `total_record_count` in the result) — **does
nothing against this checkout**, because the local tool never returns those
keys.

### 2.4 Security observations

- `projects.search` in this checkout returns every matching project on the
  instance regardless of the caller's rights, and because its payload has no
  `pid`, `ProjectAccessPreHook` cannot gate it. Upstream deleted the tool for
  this reason.
- `records.save` here is live and `destructive: true`; upstream disabled it.
  `ProjectAccessPreHook` still limits writes to projects the user has rights
  to, and `PhiFieldPreHook` blocks identifier fields — *if that hook is
  registered* (§3.2).
- `toolSearchProjects()` interpolates nothing (parameterised `db_query`), so
  the exposure is authorization, not injection.

---

## 3. How they fit together

### 3.1 One agent turn, end to end

1. Chatbot EM assembles context and calls
   `SecureChatAI::callAI($model, {messages, agent_mode: true, session_id, …}, $pid, $user)` in-process.
2. `callAI` → `runAgentLoop`: forces a schema-capable model, discovers tools
   from each prefix's `tools.json`, prepends the router system message with the
   text tool catalog, disables native tool calling, sets the `agent_response`
   JSON schema.
3. Model returns `{"tool_call": {...}}`. `executeToolCall` runs the 6-phase
   pipeline: schema check → validation → `ProjectAccessPreHook` (+ any
   configured hooks) → EM-to-EM call into Record Tools → post-hooks.
4. Record Tools runs `\REDCap::getData(...)` and returns a plain array; errors
   come back as data.
5. Result is capped to 8 KB and appended to the message list as a *user*
   message; the loop repeats until `final_answer` or a cap trips.
6. `sanitizeOutputForUI` unwraps the envelope; `logConversationTurn` writes one
   `CappyChatTurn` row; the caller gets `{role, content, model, usage, tools_used[]}`.

### 3.2 What is actually enabled on `redcap.local` right now

Verified in the DB today, including the demo setup from 2026-08-24:

| Setting | Value | Consequence |
|---|---|---|
| `secure_chat_ai` `enabled` (system) | true | enabled everywhere |
| `enable_agent_mode` (system) | true | agent mode available |
| `redcap_chatbot` `agent_mode` (project 71) | true | PID 71 runs the agent |
| `project_agent_tool_em_prefixes` (project 71) | `redcap_agent_record_tools` | tools visible to PID 71 only |
| `redcap_agent_record_tools` `enabled` | projects 71, 241 | |
| `pre_tool_use_hooks` / `project_pre_tool_use_hooks` | **unset** | **`PhiFieldPreHook` is not registered** — only `ProjectAccessPreHook` runs |
| `is_development_server` | 1 | `logFullRequest()` writes full prompt bodies to the log |

So on this box, PID 71's agent currently has an unfiltered `projects.search`,
a live `records.save`, and no PHI-field gate. It is a localhost instance with
synthetic data, but it is not the posture upstream ships. Undo the demo
enablement with `presentation/demo_pid71_rollback.sql`; registering
`Stanford\REDCapAgentRecordTools\PhiFieldPreHook` in `pre_tool_use_hooks` would
close the PHI gap but will also deny `records.get`/`records.search` calls that
don't name fields, since no local model alias contains "gemini".

---

## 4. Doc/code discrepancies worth fixing

1. SecureChatAI README documents monthly token/cost caps that no code reads.
2. Record Tools README documents `projects.search` and `records.save` as
   available tools; upstream has removed and disabled them respectively.
3. The chatbot repo's `CLAUDE.md` describes the preview/cache/paging contract
   (`preview_markdown`, `ref_xxx`, `cappyBuildPreview`) as current. That is
   true of Record Tools `origin/master`, not of the checkout in this tree.
4. `SecureChatAI.php` line numbers cited in `CLAUDE.md` have drifted (the
   paging passthrough is at ~1063–1085, not 915–933).

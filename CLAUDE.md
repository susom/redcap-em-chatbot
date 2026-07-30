# Project Brief

## What this is
- Cappy: REDCap External Module providing an embedded chatbot UI (React iframe) backed by SecureChatAI's agent mode, with tool-call "action links" and page-aware context.

## The 3-repo Cappy data stack (work together, all must be checked out)
| Repo | Path | Role |
|---|---|---|
| **this repo** | `modules-local/redcap-em-chatbot_v9.9.9` | Chat UI, system prompt, ajax actions |
| secureChatAI | `modules-local/secure_chat_ai_v9.9.9` | LLM orchestration, tool invocation, `tools_used` passthrough |
| REDCapAgentRecordTools | `modules-local/redcap_agent_record_tools_v9.9.9` | `records.search` etc., session recordset cache, server-built previews |

## Tech stack
- Backend: PHP 8.x (REDCap EM, `$this->framework`)
- Frontend: Create React App in `chatbot_ui/`; **committed bundle** in `chatbot_ui/build/` (no build on deploy)
- Comms: iframe `postMessage` (custom *reply protocol*, see below) + JSMO ajax

## Repo layout (key files)
- `REDCapChatBot.php` — system prompt builder (:475-500), ajax actions (`cappyPage` :450-475), page action injection, fullscreen listeners (:139-152)
- `assets/cappy-actions.js` — parent-side bridge: page-action execution, `fullscreen-on/off`
- `assets/jsmo.js` — JSMO ajax methods (`cappyPage`)
- `config.json` — **`auth-ajax-actions` must list every ajax action** or calls fail with "must be specified in the 'auth-ajax-actions' array"
- `chatbot_ui/src/App.js` — message state, auto-fullscreen effect, fullscreen-restore
- `chatbot_ui/src/components/messages/` — `messages.js` (marked+**remark-gfm** rendering), `PaginatedTable.js`, `messages.css` (table styles)
- `chatbot_ui/src/cappy-api.js` — JSMO init + `send` wrapper with background-fill retry

## Data-tool pipeline (records.search end-to-end)
1. LLM calls `records.search` → REDCapAgentRecordTools executes, caches the full result set in `$_SESSION['cappy_data_cache']` (TTL 1800s), returns `{reference, total, preview_markdown, note, ...}` (raw `records` only if `include_records=true`)
2. SecureChatAI truncates every tool result to **8000 chars, dropping trailing keys** (`SecureChatAI.php:1343`) — so response arrays must order `preview_markdown`/`note`/`message` **before** any `records` payload
3. SecureChatAI copies `reference/total/offset/limit/preview_markdown` into `tools_used[].paging` (`SecureChatAI.php:915-933`) — the **only** conduit from tool results to the UI
4. UI: `PaginatedTable` mounts on `paging`, strips the model's echoed table via `splitFirstTable`, renders the authoritative preview + Prev/Next
5. Prev/Next → `cappy-api.cappyPage({reference, offset, limit})` → JSMO → `REDCapChatBot::cappyPage()` (pid-scoped, reads the PHP session cache — **no LLM round trip**)
6. LLM-side accumulation: `reference` + new filter = in-memory narrow (evaluateLogic per record; >200 records falls back to one scoped getData — `CAPPY_FILTER_INLINE_MAX`); `append_to` + new filter = union merge into the cached working set

## Guardrails / hard-won gotchas
- **Never let the LLM echo `ref_xxx` handles to users**; prompt forbids it
- **Code-vs-label**: LLMs mistranslate coded values (`0`→"Female" when 0=Male). Choice labels are resolved **server-side** in `cappyBuildPreview` — the model must echo `preview_markdown` verbatim, never re-render from raw rows
- **remark-gfm is required** for markdown tables; without it tables render as collapsed raw pipes (this bug cost a debugging round)
- Response **key order matters** because of the 8000-char trailing-key truncation
- Auto-fullscreen uses **explicit on/off messages**, never a toggle (a second data result could flip it off)
- Fullscreen restore after login-timeout reload needs a **resize retry loop** in App.js (iframe height is clipped post-timeout)
- Session cache serializes full recordsets per request — fine at dev scale; consider APCu/file cache for very wide projects
- PHP session cookie is the only session identity for ajax actions; cache entries are tool-tagged and pid-scoped

## Local dev / test environment
- Test project: **PID 70 "PIVIE"**, 1000 synthetic records, 800+ fields; data table `redcap_data8`
- DB: docker `rc1_db` → `docker exec rc1_db mysql -uredcap -predcap123 redcap`
- Logs: `~/Work/redcap/logs/redcap-em-chatbot.log`, `~/Work/redcap/logs/redcap_agent_record_tools.log` (emDebug lines: `Cappy AI request`/`Agent tool response`)
- Untracked local test junk (do not commit): `CAPPY_TEST_QUESTIONS.pdf`, `synth_pivie_1000.csv`, `pivie_data_dictionary.csv`, `tools/` (CSV→REDCap import scripts)
- Frontend rebuild: `cd chatbot_ui && npm run build` (commit `build/`); deps: react-scripts 4, added `remark-gfm` in 2026-07

## How to verify changes
- PHP: `php -l <file>` (no other static analysis installed; phpstan 2.1.17 phar was at `/tmp/phpstan.phar` with REDCap stubs at `/tmp/phpstan-stubs/redcap.php` — ephemeral, recreate if needed; stub causes false positives on inherited EM methods)
- End-to-end: ask Cappy on PID 70 e.g. "How many adverse events have severity 3?" → expect preview table + pagination bar; then "also severity 2" (append union), "of those, show females" (in-memory narrow), click Next (no-LLM page turn). Watch the two logs above.

## Roadmap / TODO
- File/image uploads from chat (readme request)
- Streaming responses
- Per-record links from preview tables (record home page)
- Cache storage backend (APCu/file) if session serialization becomes a bottleneck

## Claude Rules (Repo Safety)
- Never commit/push unless explicitly asked
- Small reviewable diffs; follow existing patterns; no new libraries without asking
- When changing `records.search` behavior, keep `tools.json` descriptions and the SecureChatAI paging contract in sync across all three repos

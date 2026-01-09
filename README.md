# Cappy — REDCap Chatbot External Module (EM)

Cappy is the **UI + orchestration layer** of the REDCap AI Ecosystem. It injects a React-based chatbot into REDCap pages (system-wide) or runs as a standalone embedded app, then assembles **project-scoped context** (system prompt + optional project metadata + RAG retrieval) and routes requests to **SecureChatAI** for model execution.

**Requires**: SecureChatAI EM (model gateway) and optionally RedcapRAG EM (retrieval).
**Primary goal**: give REDCap users a consistent, safe, project-aware AI assistant experience.

---

## Recent Improvements (2026-01-08)

**Context Compression**: Cappy now supports **infinite-length conversations** via automatic context compression:
- Triggers after 20 messages (configurable)
- Keeps system context + last 6 messages (3 recent Q&A pairs)
- Summarizes old turns using SecureChatAI (cheap model: deepseek)
- Injects summary as new system message
- Enables unlimited conversation length without token/cost blowup

**Production UX Hardening**:
- **Error toasts**: User-facing error messages (5s auto-dismiss) for network/API failures
- **Loading state failsafe**: 30-second timeout ensures spinner always clears
- **Tool usage indicators**: Discreet gray text showing which agent tools were used
- **Markdown formatting**: Bullet points (`•` and `*`) now render as proper lists

**Architecture Documentation**:
- **Dual context architecture**: `apiContext` (sent to AI) vs `chatContext` (UI display)
- Compression only affects `apiContext`, full conversation history preserved in UI
- Browser-only storage (IndexedDB via Dexie) - no server-side conversation persistence

---

## Where Cappy Fits in the REDCap AI Ecosystem

Cappy is the **third pillar**:

- **SecureChatAI EM**: model gateway (OpenAI, Gemini, Claude, Llama…), parameter normalization, logging, optional agentic tool loop  
- **RedcapRAG EM**: retrieval + storage of context documents in **namespaced** vector stores  
- **Cappy (REDCap Chatbot EM)**: **UI injection + context assembly + orchestration**, calling SecureChatAI and optionally pulling context via RedcapRAG

---

## What Cappy Does

- Injects chatbot UI into REDCap pages (system-wide) via `redcap_every_page_top`
- Runs as a standalone embedded UI via a project page (`standalone.php`)
- Assembles the prompt at request-time:
  - project/system “system context”
  - optional project metadata snapshot
  - RAG retrieval results (project namespace)
- Calls SecureChatAI for the selected model (`gpt-4o`, `o1`, `claude`, etc.)
- Supports agent mode (experimental) by passing `agent_mode=true` through to SecureChatAI

---

## Architecture Overview

### End-to-end request flow (normal mode)

```text
[User]
  → [Cappy React UI]
    → (AJAX: redcap_module_ajax callAI)
      → Cappy builds messages:
           - optional Project Metadata
           - RAG: getRelevantDocuments(namespace, messages)
           - system/project system_context
      → SecureChatAI.callAI(model, params, pid)
      → normalized response
      → Cappy formats response for UI
  → [React UI renders]
```

**Key idea:** RAG is **query-time retrieval**, not stored chat memory.  
Each call can re-evaluate context using the latest user input and conversation state.

---

## Agent Mode (Experimental)

Cappy can request agentic behavior by enabling the project setting:

- `agent_mode`

When enabled:
- Cappy adds `agent_mode=true` to the payload sent to SecureChatAI
- SecureChatAI may run a multi-step loop that:
  - selects tools from the project’s tool registry
  - validates required arguments
  - executes only registered tool endpoints
  - injects tool results back into context
  - returns a final answer

**Cappy never executes tools directly.**  
It only orchestrates and delegates to SecureChatAI.

---

## RAG Ingestion & Namespace Tooling (Included)

Cappy includes a built-in **Project RAG Tools** UI (`rag_ingest/ragit.php`) that allows admins to manage ingestion for a project namespace:

- Upload JSON documents → ingested into a RedcapRAG namespace
- Hybrid dense + sparse search/debug
- List stored documents
- Delete individual documents
- Purge an entire namespace

This means **ingestion into specific project namespaces is available directly inside the Cappy EM**.

---

## UI Modes

### 1) System-wide UI Injection (Embedded)

- Hook: `redcap_every_page_top($project_id)`
- Controlled by system setting:
  - `enable-system-ui-injection`
- Page filtering supported via `chatbot_exclude_list`
  - (currently repurposed as an include list for limited production testing)

### 2) Standalone Embedded Chatbot

- Page: `pages/standalone.php`
- Provides runtime configuration via `window.cappy_project_config`
- Injects initial system context via `injectJSMO()`
- Posts `cappy-loaded` to parent window for embed coordination
- Designed to support iframe and external app embedding

---

## Context Assembly Rules

Cappy builds the effective system context per request in this approximate order:

1. **Optional Project Metadata**
  - Enabled via `inject-project-metadata`
  - Generated by `getREDCapProjectContext()`
  - Cached for ~1 hour
2. **RAG Retrieval**
  - Namespace: `project_rag_project_identifier`
  - Retrieval: `getRelevantDocuments(namespace, messages)`
  - Injected as `RAG Data:\n\n...`
3. **System Prompt**
  - Project-level `project_chatbot_system_context`
  - Falls back to system-level `chatbot_system_context`

Context is always injected as **system messages**, not user messages.

---

## Public Methods

### UI / Injection
- `redcap_every_page_top($project_id)`
- `injectIntegrationUI()`
- `generateAssetFiles(): array`
- `injectJSMO($data = null, $init_method = null): void`

### Chat Handling
- `redcap_module_ajax($action, $payload, $project_id, ...)`

### Context Helpers
- `sanitizeInput($payload): array`
- `appendSystemContext($chatMlArray, $newContext)`
- `getREDCapProjectContext()`
- `getSetting($key, $default = null)`
- `setIfNotBlank(&$arr, $key, $value, $cast = null)`

### Dependencies
- `getSecureChatInstance()`
- `getRedcapRAGInstance()`

---

## Response Format

```json
{
  "response": {
    "role": "assistant",
    "content": "..."
  },
  "model": "gpt-4o",
  "usage": {
    "prompt_tokens": 42,
    "completion_tokens": 128,
    "total_tokens": 170
  }
}
```

---

## Non-Goals & Guardrails

### Non-Goals
- Not a clinical decision system
- Not a replacement for REDCap permissions
- Not a general-purpose agent runtime
- Not a persistent memory store beyond RAG namespaces

### Guardrails
- Project-scoped RAG namespaces
- Tool execution only via SecureChatAI registry
- Centralized logging and auditability
- Explicit configuration for UI injection and context sources

---

## Summary

Cappy is the **chat surface and orchestration layer** for REDCap AI:

- UI everywhere (or standalone)
- Context assembled intentionally and transparently
- Retrieval via RedcapRAG
- Execution via SecureChatAI

Together, these form a modular, compliant, and extensible REDCap AI Ecosystem.

# REDCap Chatbot External Module (EM)

A front-end chat widget for REDCap with optional RAG augmentation.  
This module injects a React-based chatbot UI into REDCap pages and connects it to SecureChatAI (LLM backend) and, optionally, RedcapRAG (vector retrieval).

---

## Features

### Chatbot UI
- React widget injected on system or project pages.
- Floating badge with expandable chat window.
- Optional full-screen and draggable layout.
- Customizable title, intro message, theme, and CSS overrides.

### Dynamic Context
- Supports postMessage-based context injection.
- Can combine project/system context with dynamic page-level context.
- Optional retrieval-augmented generation (RAG) if RedcapRAG is installed.

### LLM Support
- All requests routed through SecureChatAI EM.
- Supports GPT, Claude, Gemini, DeepSeek, and xAI (via SecureChatAI).
- Configurable model and parameters at system or project level.

---

## RAG Integration (Optional)

If the RedcapRAG EM is installed and enabled, Chatbot EM retrieves relevant documents before generating an LLM answer.

### Retrieval Flow
1. User sends a message in the chat.
2. Chatbot EM forwards the cleaned query to RedcapRAG.
3. RedcapRAG performs:
   - Dense similarity search (embeddings)
   - Sparse BM25-style search (keyword)
   - Hybrid scoring
4. Top-K retrieved documents are returned to Chatbot EM.
5. Chatbot embeds these documents into the final prompt passed to SecureChatAI.

### RAG Payload Provided to SecureChatAI

```json
{
  "query": "user question",
  "rag_context": [
    {
      "id": "...",
      "dense": 0.84,
      "sparse": 0.91,
      "similarity": 0.87,
      "content": "First part of the retrieved document..."
    }
  ]
}
```

### Required Setting

In Chatbot EM Project Settings:

- `project_rag_project_identifier`  
  The namespace identifier that RedcapRAG uses for document retrieval.

If this setting is empty, RAG is skipped entirely.

---

## Dependencies

- SecureChatAI External Module  
  Handles all LLM interactions, model parameters, and defaults.

- RedcapRAG External Module (optional)  
  Provides ingestion, hybrid vector search, and document retrieval.  
  Chatbot EM does not manage ingestion; it only calls RAG helper functions.

---

## Setup

### 1. Install Dependencies
Enable SecureChatAI.  
Enable RedcapRAG if you want RAG retrieval.

### 2. Build / Deploy React Widget
The compiled UI bundle should be located in:

```
chatbot_ui/build/
```

The module injects these assets automatically.

### 3. Configure Project Settings

Configurable fields include:
- Chatbot title
- Intro message
- System or project context blocks
- Custom CSS overrides
- Model and model parameters
- Allowed postMessage event types
- RAG namespace (optional)

---

## postMessage Integration

Chatbot EM listens for messages from the parent page.  
Use this to provide page-specific or record-specific context.

Supported message types:
- `collapse-cappy`
- `navigate`
- Custom types defined in `project_allowed_context_types`

Example:

```javascript
window.postMessage({
  type: "project_detail",
  data: {
    record_id: 123,
    demographics: { ... }
  }
}, "*");
```

The chatbot will incorporate this data into its next LLM request.

---

## Model Parameter Handling

- Project-level settings override system defaults.
- Empty settings are omitted to avoid sending blank parameters.
- Reasoning effort is only included for models that support it.
- Final parameter resolution is handled by SecureChatAI EM.

---

## Tips and Notes

- RedcapRAG is optional; leave the namespace blank to disable retrieval.
- Chatbot EM does not ingest documents. Use RedcapRAG admin UI for ingestion.
- Dynamic context via postMessage is powerful for dashboards and embedded workflows.
- Use RedcapRAG's debug panel to test hybrid search relevance.
- Chatbot EM respects the `chatbot_exclude_list`, which currently acts as an inclusion list during testing.

---

## Example Workflow

1. User asks a question.
2. Chatbot EM gathers:
   - System context
   - Project context
   - Dynamic postMessage context
3. If RedcapRAG is enabled:
   - Relevant documents are retrieved and included.
4. SecureChatAI merges all context and sends it to the selected LLM.
5. The LLM produces the final answer, optionally grounded in retrieved documents.


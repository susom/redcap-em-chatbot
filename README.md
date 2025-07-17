# REDCap Chatbot EM

This External Module (EM) integrates a Support Chat Bot UI widget into Stanford REDCap.

---

## About the Chatbot

- Injects a React-based chat widget into REDCap project and/or system pages.
- Supports dynamic project-specific context and system-level fallback.
- Project-level settings can customize **title**, **intro message**, **model parameters**, and **dynamic postMessage context injection**.
- Integrates with SecureChatAI (handles LLM backend calls) and RedcapRAG (retrieves relevant docs for better answers).

---

## Integration and Dependencies

- [SecureChatAI External Module](https://github.com/susom/secureChatAI) (embedding and LLM orchestration)
- [REDCap RAG External Module](https://github.com/susom/redcapRAG) (Optional) 
---

## Setup & Configuration

1. **Install Dependencies:**  
   - SecureChatAI and RedcapRAG modules must be installed and enabled.

2. **Build Assets:**  
   - The widget is built with React; the build output is typically in `chatbot_ui/build/`.

3. **Configure Project/System Settings:**  
   - Project settings (override system defaults):
     - `project_chatbot_title`
     - `project_chatbot_intro`
     - `project-llm-model`, `project-gpt-temperature`, etc.
     - `project_allowed_context_types` (comma-separated, e.g. `project_detail,project_dashboard`)
     - `project_chatbot_system_context`
     - `project_chatbot_custom_css`
   - System settings (global defaults):
     - `llm-model`, `gpt-temperature`, etc.

4. **Widget UI Injection:**
   - Controlled via system setting `"chatbot_exclude_list"` (currently used as an *include* list for targeted testing).
   - You can toggle injection for specific pages, projects, or everywhere.

---

## Dynamic Project Context (postMessage Injection)

- Supports postMessage-based context injection for embedding the chatbot in iframes or custom dashboards.
- Allowed event types are set in `project_allowed_context_types` (comma-separated).
- Frontend listens for `postMessage` events of these types to update the chat context.
- **If you embed the chatbot, document which event types to use for context.**

---

## Model Parameter Handling

- **Model parameters** (`model`, `temperature`, `top_p`, `frequency_penalty`, `presence_penalty`, `max_tokens`, `reasoning`) are looked up in project settings, then system settings, then fallback to SecureChatAI internal defaults.
- **Only non-empty values are sent**—blank settings are omitted, avoiding unwanted zeroes or empty strings.
- **Reasoning Effort** is only sent to models that support it (`o1`, `o3-mini`).

---

## Gotchas & Tips

- **Model Parameter Naming:**  
  - System-level: `llm-model`, `gpt-temperature`, etc.
  - Project-level: `project-llm-model`, `project-gpt-temperature`, etc.
  - Always use the provided `getSetting()` method to handle fallback logic.

- **Frontend postMessage:**  
  - The allowed types must be in your project settings.
  - Widget supports switching context dynamically via postMessage—useful for dashboards or embedded contexts.

---

## Example: Enabling the Chatbot on a Project

1. Enable REDCap Chatbot, SecureChatAI, and RedcapRAG on your REDCap project.
2. In Project Settings for Chatbot EM, set:
   - **Chatbot Title**
   - **Intro Message**
   - **Model and parameters**
   - **Allowed postMessage event types**
   - (Optional) Custom CSS

3. To send custom project context via iframe:
    ```js
    window.parent.postMessage({
      type: 'project_detail',
      projectContext: { ... }
    }, '*');
    ```
    (where `'project_detail'` is one of your allowed types)


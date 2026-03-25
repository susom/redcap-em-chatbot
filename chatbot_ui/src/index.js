import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import { ChatContextProvider } from './contexts/Chat';
import App from './App';
import * as serviceWorkerRegistration from './serviceWorkerRegistration';
import reportWebVitals from './reportWebVitals';

const projectContextRef = { current: null }; // Mutable ref-like object
const contextStore = {};
const ALLOWED_NAMESPACES = window.cappy_project_config?.allowed_context_namespaces || [];

const summarizeContext = (context) => (
  Object.entries(context)
    .map(([k, v]) => {
      if (typeof v === "object") {
        return `${k}: ${JSON.stringify(v, null, 2)}`;
      }
      return `${k}: ${v}`;
    })
    .join('\n')
);

const buildCombinedContext = (store) => {
  return Object.values(store)
    .map(({ label, summary }) => `${label}:\n${summary}`)
    .join('\n\n');
};

// Listen for postMessage at the global/window level
window.addEventListener('message', (event) => {
  if (!event.data || !event.data.type) return;

  if (event.data.type === 'context-inject') {
    const { namespace, label, context } = event.data;
    if (!namespace || !context || typeof context !== 'object') {
      console.warn('[CAPPY] context-inject missing namespace or context', event.data);
      return;
    }
    if (ALLOWED_NAMESPACES.length > 0 && !ALLOWED_NAMESPACES.includes(namespace)) {
      console.warn('[CAPPY] namespace not allowed:', namespace);
      return;
    }
    contextStore[namespace] = { label: label || namespace, summary: summarizeContext(context) };
    projectContextRef.current = buildCombinedContext(contextStore);
  }

  if (event.data.type === 'context-clear') {
    if (event.data.namespace) {
      delete contextStore[event.data.namespace];
    } else {
      Object.keys(contextStore).forEach(k => delete contextStore[k]);
    }
    projectContextRef.current = buildCombinedContext(contextStore);
  }
});

const root = ReactDOM.createRoot(document.getElementById('chatbot_ui_container'));
root.render(
  <React.StrictMode>
      <ChatContextProvider projectContextRef={projectContextRef}>
          <App />
      </ChatContextProvider>
  </React.StrictMode>
);

// If you want your app to work offline and load faster, you can change
// unregister() to register() below. Note this comes with some pitfalls.
// Learn more about service workers: https://cra.link/PWA
serviceWorkerRegistration.unregister();

// If you want to start measuring performance in your app, pass a function
// to log results (for example: reportWebVitals(console.log))
// or send to an analytics endpoint. Learn more: https://bit.ly/CRA-vitals
reportWebVitals();

window.REDCap_Chatbot = App;

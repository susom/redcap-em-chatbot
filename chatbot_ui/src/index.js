import React, { useRef, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import { ChatContextProvider } from './contexts/Chat';
import App from './App';
import * as serviceWorkerRegistration from './serviceWorkerRegistration';
import reportWebVitals from './reportWebVitals';

const projectContextRef = { current: null }; // Mutable ref-like object
const ALLOWED_CONTEXT_TYPES = window.cappy_project_config?.allowed_context_types;

// Listen for postMessage at the global/window level
window.addEventListener('message', (event) => {
  console.log("message received?" , ALLOWED_CONTEXT_TYPES, event.data.type);

  if (!event.data || !event.data.type) return;
  if (ALLOWED_CONTEXT_TYPES.includes(event.data.type)) {
    const summary = Object.entries(event.data.projectContext)
    .map(([k, v]) => {
      if (typeof v === "object") {
        return `${k}: ${JSON.stringify(v, null, 2)}`;
      } else {
        return `${k}: ${v}`;
      }
    })
    .join('\n');

    projectContextRef.current = summary;
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

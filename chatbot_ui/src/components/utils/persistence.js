// Persist Cappy widget UI state (view, position, size, fullscreen) AND the
// active chat sessionId in sessionStorage. Everything in here dies when
// the tab closes — no client-side persistence beyond the tab. The actual
// conversation is rebuilt on demand from server-side logs (see
// rebuildSession in REDCapChatBot.php); we never write message content
// to either browser store.

const IDLE_MS = 60 * 60 * 1000; // 60 minutes

function getScopeKey() {
    const pid = window.cappy_project_config?.pid ?? 'global';
    return `cappy_ui_state_${pid}`;
}

function getSessionKey() {
    const pid = window.cappy_project_config?.pid ?? 'global';
    return `cappy_chat_session_${pid}`;
}

export function loadUiState() {
    try {
        const raw = sessionStorage.getItem(getScopeKey());
        if (!raw) return null;
        const state = JSON.parse(raw);
        if (!state || typeof state.updatedAt !== 'number') return null;
        if (Date.now() - state.updatedAt > IDLE_MS) {
            sessionStorage.removeItem(getScopeKey());
            return null;
        }
        return state;
    } catch (e) {
        return null;
    }
}

export function saveUiState(partial) {
    try {
        const raw = sessionStorage.getItem(getScopeKey());
        const prev = raw ? JSON.parse(raw) : {};
        const next = { ...prev, ...partial, updatedAt: Date.now() };
        sessionStorage.setItem(getScopeKey(), JSON.stringify(next));
    } catch (e) {
        // ignore storage failures (private mode, quota, etc.)
    }
}

export function clearUiState() {
    try {
        sessionStorage.removeItem(getScopeKey());
    } catch (e) {
        // ignore
    }
}

// ---- Chat session (sessionStorage, per-tab) ----
//
// We persist ONLY the sessionId. The actual conversation lives in
// redcap_external_modules_log via SecureChatAI — see rebuildSession() in
// REDCapChatBot.php. This keeps sessionStorage free of any message content
// (PHI), and a single small string survives page navigations within the tab.
// Per-project scoping avoids one project's session id leaking into another
// when the user opens a different project in a new tab.

export function loadChatSession() {
    try {
        const raw = sessionStorage.getItem(getSessionKey());
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || typeof data.sessionId !== 'string') return null;
        return { sessionId: data.sessionId };
    } catch (e) {
        return null;
    }
}

export function saveChatSession({ sessionId }) {
    try {
        sessionStorage.setItem(getSessionKey(), JSON.stringify({
            sessionId,
            savedAt: Date.now(),
        }));
    } catch (e) {
        // ignore storage failures (private mode, quota, etc.)
    }
}

export function clearChatSession() {
    try {
        sessionStorage.removeItem(getSessionKey());
    } catch (e) {
        // ignore
    }
}

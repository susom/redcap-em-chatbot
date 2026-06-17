// Persist Cappy widget UI state across page reloads, scoped per project (pid).
// Used to reopen the chat in the same view/position with the active session
// restored, but only if the last activity was recent (idle expiry).

const IDLE_MS = 60 * 60 * 1000; // 60 minutes

function getScopeKey() {
    const pid = window.cappy_project_config?.pid ?? 'global';
    return `cappy_ui_state_${pid}`;
}

export function loadUiState() {
    try {
        const raw = localStorage.getItem(getScopeKey());
        if (!raw) return null;
        const state = JSON.parse(raw);
        if (!state || typeof state.updatedAt !== 'number') return null;
        if (Date.now() - state.updatedAt > IDLE_MS) {
            localStorage.removeItem(getScopeKey());
            return null;
        }
        return state;
    } catch (e) {
        return null;
    }
}

export function saveUiState(partial) {
    try {
        const raw = localStorage.getItem(getScopeKey());
        const prev = raw ? JSON.parse(raw) : {};
        const next = { ...prev, ...partial, updatedAt: Date.now() };
        localStorage.setItem(getScopeKey(), JSON.stringify(next));
    } catch (e) {
        // ignore storage failures (private mode, quota, etc.)
    }
}

export function clearUiState() {
    try {
        localStorage.removeItem(getScopeKey());
    } catch (e) {
        // ignore
    }
}

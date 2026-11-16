import React, { useState, useEffect, useContext } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import Draggable from 'react-draggable';
import ResizableContainer from './components/ResizableContainer';
import { loadUiState, saveUiState } from './components/utils/persistence';
import { ChatContext } from './contexts/Chat';
import './App.css';
import './assets/styles/global.css';

// Wide view fills this fraction of the viewport in both axes.
const FULLSCREEN_VIEWPORT_RATIO = 0.88;

// ...except the WIDTH, which is also capped: past ~1400px a chat thread is mostly
// empty gutter, and this is the width the layout was tuned against (88vw on a 15"
// laptop ≈ 1480px). Height stays uncapped — more vertical room is always useful.
// Lower this one number if wide view still reads too wide on very large monitors.
const FULLSCREEN_MAX_WIDTH = 1400;

const fullscreenHeight = () => Math.floor(window.innerHeight * FULLSCREEN_VIEWPORT_RATIO);

// Wide-view geometry: capped size, centered in the viewport. Used by all four entry
// points into fullscreen (restore-on-mount, auto-fullscreen on a table, the header
// toggle, and the window-resize clamp) so they can't drift apart.
const fullscreenBox = () => {
    const width  = Math.min(
        Math.floor(window.innerWidth * FULLSCREEN_VIEWPORT_RATIO),
        FULLSCREEN_MAX_WIDTH
    );
    const height = fullscreenHeight();
    return {
        size: { width, height },
        position: {
            x: Math.floor((window.innerWidth  - width)  / 2),
            y: Math.floor((window.innerHeight - height) / 2),
        },
    };
};

function App() {
    const { greet, chatContext } = useContext(ChatContext);
    const defaultExpandedWidth  = window?.cappy_project_config?.expanded_width  || 360;
    // Default to 80% of the viewport height (capped at the resize max) when the
    // project hasn't set an explicit expanded_height.
    const defaultExpandedHeight = window?.cappy_project_config?.expanded_height
        || Math.min(Math.floor(window.innerHeight * 0.8), 1000);

    // Restore prior UI state (per-project, idle-expiring). Fullscreen IS
    // restored — the user expects the chat to stay in the mode they left it
    // in (fullscreen → fullscreen, expanded → expanded, splash → splash).
    // The "backdrop races" issue from before was tied to a different code path;
    // restoring fullscreen from a clean re-mount works fine.
    const persistedUi = loadUiState();
    const restoredExpanded = !!persistedUi;

    const splashPosition = () => ({
        x: window.innerWidth  - 30 - 120,
        y: window.innerHeight - 30 - 120,
    });

    const [currentView, setCurrentView] = useState(persistedUi?.view || 'splash');
    const [defaultPosition, setDefaultPosition] = useState(() => (
        restoredExpanded && persistedUi.position ? persistedUi.position : splashPosition()
    ));
    const [isFullscreen, setIsFullscreen] = useState(!!(persistedUi?.isFullscreen));

    const [size, setSize] = useState(
        restoredExpanded && persistedUi.size
            ? persistedUi.size
            : { width: defaultExpandedWidth, height: defaultExpandedHeight }
    );

    // Persist UI state whenever it changes
    useEffect(() => {
        saveUiState({ view: currentView, position: defaultPosition, size, isFullscreen });
    }, [currentView, defaultPosition, size, isFullscreen]);

    // On mount, sync the host iframe sizing to the restored view.
    // After a session timeout + login, the parent page may not be ready
    // when the first postMessage fires, so the iframe stays at its previous
    // (e.g. splash) size and the fullscreen-mode chat appears clipped.
    // Retrying a few times with a delay reliably wakes the parent up.
    useEffect(() => {
        const sendResize = () => {
            if (currentView === 'splash') {
                window.parent.postMessage({ type: 'resize-cappy', source: 'splash', width: 120, height: 120 }, '*');
            } else {
                window.parent.postMessage({ type: 'resize-cappy', source: currentView, width: size.width, height: size.height }, '*');
            }
        };
        sendResize();
        const t1 = setTimeout(sendResize, 100);
        const t2 = setTimeout(sendResize, 400);
        const t3 = setTimeout(sendResize, 1500);
        return () => { clearTimeout(t1); clearTimeout(t2); clearTimeout(t3); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // On mount, if we restored to fullscreen mode, apply the fullscreen
    // size/position (otherwise the widget is sized to expanded dimensions
    // and doesn't actually fill the screen).
    useEffect(() => {
        if (isFullscreen) {
            const fs = fullscreenBox();
            setSize(fs.size);
            setDefaultPosition(fs.position);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Auto-fullscreen when the assistant renders a markdown table — tabular
    // data is unreadable in the narrow widget. Idempotent: only ever turns
    // fullscreen ON, never off (the user can exit manually via the header button).
    useEffect(() => {
        if (isFullscreen || currentView === 'splash') return;
        const last = [...(chatContext || [])].reverse().find(m => m?.assistant_content);
        if (!last) return;
        // Markdown table = a separator line of pipes/dashes (| --- | --- |)
        if (!/^\s*\|[\s:|-]*---[\s:|-]*\|/m.test(last.assistant_content)) return;
        const fs = fullscreenBox();
        setSize(fs.size);
        setDefaultPosition(fs.position);
        setIsFullscreen(true);
        window.parent.postMessage({ type: 'fullscreen-on' }, '*');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chatContext]);

    const changeView = (viewName) => {
        if (viewName === 'splash') {
            if (isFullscreen) {
                // Leaving fullscreen straight to the splash badge: clear fullscreen
                // state/backdrop here. Anchor below is for the 120x120 badge — anchoring
                // with the EXPANDED widget size (~360x800) left the badge floating near
                // the top instead of the bottom-right.
                setIsFullscreen(false);
                const c = document.getElementById('chatbot_ui_container');
                if (c) c.classList.remove('cappy-fullscreen');
            }
            // Always snap the splash badge (120x120) to the bottom-right corner,
            // whether we came from fullscreen or the expanded widget.
            setDefaultPosition({
                x: window.innerWidth  - 30 - 120,
                y: window.innerHeight - 30 - 120,
            });
            window.parent.postMessage({ type: 'resize-cappy', source: 'splash', width: 120, height: 120 }, '*');
        } else if (viewName === 'home') {
            const config = window?.cappy_project_config || {};
            const w = config.expanded_width  || defaultExpandedWidth;
            const h = config.expanded_height || defaultExpandedHeight;
            // Only reposition when expanding from splash
            if (currentView === 'splash') {
                // Set the size and anchor the bottom-right to the badge's
                // bottom-right (30px margins) so both stay in sync.
                setSize({ width: w, height: h });
                setDefaultPosition({
                    x: window.innerWidth  - 30 - w,
                    y: window.innerHeight - 30 - h,
                });
                // Badge-open at the start of a session: fire the preemptive
                // greeting (no-op unless configured and chat is empty).
                if (typeof greet === 'function') greet();
            }
            window.parent.postMessage({
                type: 'resize-cappy',
                source: viewName,
                width: w,
                height: h
            }, '*');
        }
        setCurrentView(viewName);
    };

    useEffect(() => {
        const handler = (event) => {
            if (event.data && event.data.type === 'collapse-cappy') {
                changeView('splash');
            }
            if (event.data?.type === 'navigate') {
                changeView(event.data.view);
            }
            if (event.data?.type === 'full-screen') {
                setIsFullscreen(prev => {
                    const next = !prev;
                    if (next) {
                        const fs = fullscreenBox();
                        setSize(fs.size);
                        setDefaultPosition(fs.position);
                    } else {
                        setSize({ width: defaultExpandedWidth, height: defaultExpandedHeight });
                        // Anchor bottom-right to the badge's bottom-right so the
                        // shrunk widget stays on-screen instead of overflowing.
                        setDefaultPosition({
                            x: window.innerWidth  - 30 - defaultExpandedWidth,
                            y: window.innerHeight - 30 - defaultExpandedHeight,
                        });
                    }
                    return next;
                });
            }
        };
        window.addEventListener('message', handler);
        return () => window.removeEventListener('message', handler);
    }, [isFullscreen]);

    // Keep the widget fully on-screen when the viewport shrinks (e.g. moving the
    // browser from a large monitor to a smaller laptop screen). We clamp the SIZE to
    // the viewport first — otherwise re-anchoring bottom-right with a width/height
    // larger than the screen pushes the widget half off the left/top edge.
    useEffect(() => {
        const onResize = () => {
            const margin = 30;
            const vw = window.innerWidth;
            const vh = window.innerHeight;

            if (isFullscreen) {
                const fs = fullscreenBox();
                setSize(fs.size);
                setDefaultPosition(fs.position);
                return;
            }

            if (currentView === 'splash') {
                setDefaultPosition({
                    x: Math.max(margin, vw - margin - 120),
                    y: Math.max(margin, vh - margin - 120),
                });
                return;
            }

            // Expanded: shrink to fit the viewport (respect min constraints), then
            // re-anchor bottom-right but never past the top/left edges.
            const w = Math.min(size.width,  Math.max(320, vw - margin * 2));
            const h = Math.min(size.height, Math.max(480, vh - margin * 2));
            if (w !== size.width || h !== size.height) {
                setSize({ width: w, height: h });
            }
            setDefaultPosition({
                x: Math.max(margin, vw - margin - w),
                y: Math.max(margin, vh - margin - h),
            });
        };
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, [isFullscreen, currentView, size.width, size.height]);

    let ViewComponent;
    switch (currentView) {
        case 'home':
            ViewComponent = <Home changeView={changeView} />;
            break;
        case 'splash':
        default:
            ViewComponent = <Splash changeView={changeView} />;
            break;
    }

    const content = (
        <div className={`draggable-container ${currentView}${isFullscreen ? ' fullscreen' : ''}`}>
            <ResizableContainer
                width={size.width}
                height={size.height}
                minConstraints={[320, 480]}
                // Must be >= the wide-view size, or grabbing the resize handle while in
                // wide view snaps the panel down (ResizableBox passes the width/height
                // props through unclamped, but clamps every drag to maxConstraints).
                maxConstraints={[FULLSCREEN_MAX_WIDTH, Math.max(1000, fullscreenHeight())]}
                onResize={(e, data) => setSize({ width: data.size.width, height: data.size.height })}
                onResizeStop={(e, data) => setSize({ width: data.size.width, height: data.size.height })}
            >
                {currentView !== 'splash' ? (
                    <>
                        <Header changeView={changeView} />
                        <div className="content">{ViewComponent}</div>
                        <Footer changeView={changeView} />
                    </>
                ) : (
                    ViewComponent
                )}
            </ResizableContainer>
        </div>
    );

    return (
        <Draggable
            handle=".handle"
            position={defaultPosition}
            onStop={(e, data) => setDefaultPosition({ x: data.x, y: data.y })}
        >
            {content}
        </Draggable>
    );
}

export default App;

import React, { useState, useEffect, useContext } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';
import Draggable from 'react-draggable';
import ResizableContainer from './components/ResizableContainer';
import { loadUiState, saveUiState } from './components/utils/persistence';
import { ChatContext } from './contexts/Chat';
import './App.css';
import './assets/styles/global.css';

function App() {
    const { greet } = useContext(ChatContext);
    const defaultExpandedWidth  = window?.cappy_project_config?.expanded_width  || 360;
    // Default to 80% of the viewport height (capped at the resize max) when the
    // project hasn't set an explicit expanded_height.
    const defaultExpandedHeight = window?.cappy_project_config?.expanded_height
        || Math.min(Math.floor(window.innerHeight * 0.8), 1000);

    // Restore prior UI state (per-project, idle-expiring). Fullscreen is not
    // restored — reopen as a normal expanded widget to avoid backdrop races.
    const persistedUi = loadUiState();
    const restoredExpanded = persistedUi && !persistedUi.isFullscreen;

    const splashPosition = () => ({
        x: window.innerWidth  - 30 - 120,
        y: window.innerHeight - 30 - 120,
    });

    const [currentView, setCurrentView] = useState(persistedUi?.view || 'splash');
    const [defaultPosition, setDefaultPosition] = useState(() => (
        restoredExpanded && persistedUi.position ? persistedUi.position : splashPosition()
    ));
    const [isFullscreen, setIsFullscreen] = useState(false);

    const [size, setSize] = useState(
        restoredExpanded && persistedUi.size
            ? persistedUi.size
            : { width: defaultExpandedWidth, height: defaultExpandedHeight }
    );

    // Persist UI state whenever it changes
    useEffect(() => {
        saveUiState({ view: currentView, position: defaultPosition, size, isFullscreen });
    }, [currentView, defaultPosition, size, isFullscreen]);

    // On mount, sync the host iframe sizing to the restored view
    useEffect(() => {
        if (currentView === 'splash') {
            window.parent.postMessage({ type: 'resize-cappy', source: 'splash', width: 120, height: 120 }, '*');
        } else {
            window.parent.postMessage({ type: 'resize-cappy', source: currentView, width: size.width, height: size.height }, '*');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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
        } else if (viewName === 'home' || viewName === 'history') {
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
                        const fsWidth  = Math.floor(window.innerWidth  * 0.88);
                        const fsHeight = Math.floor(window.innerHeight * 0.88);
                        const cx = Math.floor((window.innerWidth  - fsWidth)  / 2);
                        const cy = Math.floor((window.innerHeight - fsHeight) / 2);
                        setSize({ width: fsWidth, height: fsHeight });
                        setDefaultPosition({ x: cx, y: cy });
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
                const fsWidth  = Math.floor(vw * 0.88);
                const fsHeight = Math.floor(vh * 0.88);
                setSize({ width: fsWidth, height: fsHeight });
                setDefaultPosition({
                    x: Math.floor((vw - fsWidth)  / 2),
                    y: Math.floor((vh - fsHeight) / 2),
                });
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
        case 'history':
            ViewComponent = <History changeView={changeView} />;
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
                maxConstraints={[1400, 1000]}
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

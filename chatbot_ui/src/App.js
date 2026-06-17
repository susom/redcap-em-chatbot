import React, { useState, useEffect } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';
import Draggable from 'react-draggable';
import ResizableContainer from './components/ResizableContainer';
import { loadUiState, saveUiState } from './components/utils/persistence';
import './App.css';
import './assets/styles/global.css';

function App() {
    const defaultExpandedWidth  = window?.cappy_project_config?.expanded_width  || 360;
    const defaultExpandedHeight = window?.cappy_project_config?.expanded_height || 520;

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

    const exitFullscreen = () => {
        setIsFullscreen(false);
        setSize({ width: defaultExpandedWidth, height: defaultExpandedHeight });
        setDefaultPosition({
            x: window.innerWidth  - 30 - 120,
            y: window.innerHeight - 30 - 120,
        });
        var c = document.getElementById('chatbot_ui_container');
        if (c) c.classList.remove('cappy-fullscreen');
    };

    const changeView = (viewName) => {
        if (viewName === 'splash') {
            if (isFullscreen) exitFullscreen();
            else {
                // Always snap splash badge to bottom-right corner
                setDefaultPosition({
                    x: window.innerWidth  - 30 - 120,
                    y: window.innerHeight - 30 - 120,
                });
            }
            window.parent.postMessage({ type: 'resize-cappy', source: 'splash', width: 120, height: 120 }, '*');
        } else if (viewName === 'home' || viewName === 'history') {
            const config = window?.cappy_project_config || {};
            const w = config.expanded_width  || defaultExpandedWidth;
            const h = config.expanded_height || defaultExpandedHeight;
            // Only reposition when expanding from splash
            if (currentView === 'splash') {
                setDefaultPosition(prev => ({
                    x: prev.x - (w - 120),
                    y: prev.y - (h - 120),
                }));
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
                        setDefaultPosition({
                            x: window.innerWidth  - 30 - 120,
                            y: window.innerHeight - 30 - 120,
                        });
                    }
                    return next;
                });
            }
        };
        window.addEventListener('message', handler);
        return () => window.removeEventListener('message', handler);
    }, [isFullscreen]);

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

import React, { useState, useEffect } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';
import Draggable from 'react-draggable';
import ResizableContainer from './components/ResizableContainer';
import './App.css';
import './assets/styles/global.css';

function App() {
    const [currentView, setCurrentView] = useState('splash');
    const [defaultPosition, setDefaultPosition] = useState({ x: 0, y: 0 });
    const [isFullscreen, setIsFullscreen] = useState(false);

    const defaultExpandedWidth  = window?.cappy_project_config?.expanded_width  || 360;
    const defaultExpandedHeight = window?.cappy_project_config?.expanded_height || 520;

    const [size, setSize] = useState({
        width:  defaultExpandedWidth,
        height: defaultExpandedHeight
    });

    const exitFullscreen = () => {
        setIsFullscreen(false);
        setSize({ width: defaultExpandedWidth, height: defaultExpandedHeight });
        setDefaultPosition({ x: 0, y: 0 });
        var c = document.getElementById('chatbot_ui_container');
        if (c) c.classList.remove('cappy-fullscreen');
    };

    const changeView = (viewName) => {
        if (viewName === 'splash') {
            if (isFullscreen) exitFullscreen();
            else setDefaultPosition({ x: 0, y: 0 });
            window.parent.postMessage({ type: 'resize-cappy', source: 'splash', width: 120, height: 120 }, '*');
        } else if (viewName === 'home' || viewName === 'history') {
            const config = window?.cappy_project_config || {};
            window.parent.postMessage({
                type: 'resize-cappy',
                source: viewName,
                width: config.expanded_width || 360,
                height: config.expanded_height || 520
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
                        // Offset to center: compensate for CSS bottom:30px right:30px
                        const cx = Math.floor(30 - window.innerWidth  * 0.06);
                        const cy = Math.floor(30 - window.innerHeight * 0.06);
                        setSize({ width: fsWidth, height: fsHeight });
                        setDefaultPosition({ x: cx, y: cy });
                    } else {
                        setSize({ width: defaultExpandedWidth, height: defaultExpandedHeight });
                        setDefaultPosition({ x: 0, y: 0 });
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

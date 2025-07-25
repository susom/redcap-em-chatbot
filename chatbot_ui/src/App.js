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

    // Use config-defined defaults
    const defaultExpandedWidth = window?.cappy_project_config?.expanded_width || 360;
    const defaultExpandedHeight = window?.cappy_project_config?.expanded_height || 520;

    const [size, setSize] = useState({
        width: defaultExpandedWidth,
        height: defaultExpandedHeight
    });

    const isProjectContext = typeof window.cappy_project_config !== "undefined";

    const changeView = (viewName) => {
        if (viewName === 'splash') {
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
        if (currentView === 'splash') {
            setDefaultPosition({ x: 0, y: 0 });
        }
    }, [currentView]);

    useEffect(() => {
        const handler = (event) => {
            if (event.data && event.data.type === 'collapse-cappy') {
                changeView('splash');
            }
            if (event.data?.type === 'navigate') {
                changeView(event.data.view);
            }
        };
        window.addEventListener('message', handler);
        return () => window.removeEventListener('message', handler);
    }, []);

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
        <div className={`draggable-container ${currentView}`}>
            <ResizableContainer
                width={size.width}
                height={size.height}
                minConstraints={[320, 480]}
                maxConstraints={[600, 800]}
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
    
    return isProjectContext ? (
        content
    ) : (
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

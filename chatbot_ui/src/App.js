import React, { useState, useEffect } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';
import Draggable from 'react-draggable';
import './App.css';
import './assets/styles/global.css';

function App() {
    const [currentView, setCurrentView] = useState('splash');
    const [defaultPosition, setDefaultPosition] = useState({ x: 0, y: 0 });

    // Function to change view
    const changeView = (viewName) => {
        if (viewName === 'splash') {
            // Reset the position to bottom right
            setDefaultPosition({ x: 0, y: 0 });
        }
        setCurrentView(viewName);
    };

    // Adjust the initial position of the draggable container
    useEffect(() => {
        if (currentView === 'splash') {
            setDefaultPosition({ x: 0, y: 0 });
        }
    }, [currentView]);

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

    return (
        <div id="chatbot_ui_container">
            <Draggable handle=".handle" position={defaultPosition} onStop={(e, data) => setDefaultPosition({ x: data.x, y: data.y })}>
                <div className={`draggable-container ${currentView}`}>
                    {currentView !== 'splash' && (
                        <>
                            <Header changeView={changeView} />
                            {ViewComponent}
                            <Footer changeView={changeView} />
                        </>
                    )}
                    {currentView === 'splash' && ViewComponent}
                </div>
            </Draggable>
        </div>
    );
}

export default App;

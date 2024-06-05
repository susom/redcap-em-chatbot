import React, { useState, useEffect } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';

import './App.css';
import './assets/styles/global.css';

function App() {
    // Start with 'splash' as the initial view
    const [currentView, setCurrentView] = useState('splash');

    // Function to change view
    const changeView = (viewName) => {
        setCurrentView(viewName);
    };

    useEffect(() => {
        console.log("Current View:", currentView);
    }, [currentView]); // Log on currentView change

    // Determine which component to render based on the current view
    let ViewComponent;
    switch (currentView) {
        case 'home':
            ViewComponent = <Home changeView={changeView}/>;
            break;
        case 'history':
            ViewComponent = <History changeView={changeView}/>;
            break;
        case 'splash':
        default:
            ViewComponent = <Splash changeView={changeView} />;
            break;
    }

    return (
        <div>
            {currentView !== 'splash' && <Header changeView={changeView}/>}
            {ViewComponent}
            {currentView !== 'splash' && <Footer changeView={changeView}/>}
        </div>
    );
}

export default App;

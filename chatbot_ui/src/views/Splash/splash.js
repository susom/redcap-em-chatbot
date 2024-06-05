import React from 'react';
import "./splash.css";

export function Splash({ changeView }) {
    return (
        <div className="Splash" onClick={() => changeView('home')}>
            {/* Splash content here */}
        </div>
    );
}


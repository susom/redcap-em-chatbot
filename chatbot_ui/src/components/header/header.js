import React , {useState} from "react";
import { Container } from 'react-bootstrap';
import { Archive, ChatDots } from 'react-bootstrap-icons';
import { ArrowsFullscreen } from 'react-bootstrap-icons'; 

import "./header.css";

function Header({ changeView }) {
    const headerText = window.cappy_project_config?.title || "REDCapBot Support";
    const [isFullScreen, setIsFullScreen] = useState(false);

    const toggleFullscreen = () => {
        if (isFullScreen) {
            window.parent.postMessage({
            type: 'resize-cappy',
            width: 360,
            height: 520,
            source: 'header-toggle'
            }, '*');
        } else {
            window.parent.postMessage({ type: 'full-screen' }, '*');
        }
        setIsFullScreen(!isFullScreen);
    };
    return (
        <Container className="rcchat_header handle">
            <h1>
                <span className="logo" onClick={() => changeView('splash')}></span>
                {headerText}
                
                <button onClick={toggleFullscreen} className="fullscreen">
                    <ArrowsFullscreen size={16} />
                </button>
                <button onClick={() => changeView('history')} className="archive">
                    <Archive size={16}/>
                </button>
                <button onClick={() => changeView('home')} className="chat">
                    <ChatDots size={16}/>
                </button>
            </h1>
        </Container>
    );
}

export default Header;

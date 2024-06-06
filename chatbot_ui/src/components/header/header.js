import React from "react";
import { Container } from 'react-bootstrap';
import { Archive, ChatDots } from 'react-bootstrap-icons';
import "./header.css";

function Header({ changeView }) {
    return (
        <Container className="rcchat_header">
            <h1 onClick={() => changeView('splash')}>
                REDCapBot Support
                <button onClick={() => changeView('history')} className="archive">
                    <Archive size={20}/>
                </button>
                <button onClick={() => changeView('home')} className="chat">
                    <ChatDots size={20}/>
                </button>
            </h1>
        </Container>
    );
}

export default Header;

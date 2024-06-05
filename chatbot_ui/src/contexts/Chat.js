import React, { createContext, useState } from 'react';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children }) => {
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(null);

    const addMessage = (message) => {
        setMessages([...messages, message]);
    };

    const clearMessages = () => {
        setMsgCount(0);
        setSessionId(null);
        setMessages([]);
    }

    const replaceSession = (session) => {
        clearMessages();
        setSessionId(session.session_id);
        setMessages(session.queries);
        setMsgCount(session.queries.length);
    }

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO,  msgCount, setMsgCount, sessionId, setSessionId }}>
            {children}
        </ChatContext.Provider>
    );
};


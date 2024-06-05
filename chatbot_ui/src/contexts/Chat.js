import React, { createContext, useState } from 'react';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children }) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    const addMessage = (message) => {
        console.log("Adding message:", message);
        setApiContext([
            ...apiContext,
            { role: message.role, content: message.content },
        ]);

        setChatContext(prevState => [
            ...prevState,
            {
                user_content: message.role === 'user' ? message.content : null,
                assistant_content: message.role === 'assistant' ? message.content : null,
                timestamp: new Date().getTime(),
                input_tokens: message.input_tokens || null,
                output_tokens: message.output_tokens || null,
                input_cost: message.input_cost || null,
                output_cost: message.output_cost || null,
            },
        ]);
        console.log("Updated chatContext:", chatContext);
    };

    const updateMessage = (messageContent, index) => {
        console.log("Updating message at index:", index, "with content:", messageContent);
        setChatContext(prevState => {
            const updatedState = [...prevState];
            updatedState[index] = {
                ...updatedState[index],
                assistant_content: messageContent,
            };
            console.log("Updated chatContext after updateMessage:", updatedState);
            return updatedState;
        });
    };

    const clearMessages = () => {
        setMsgCount(0);
        setSessionId(null);
        setMessages([]);
    };

    const replaceSession = (session) => {
        clearMessages();
        setSessionId(session.session_id);
        setMessages(session.queries);
        setMsgCount(session.queries.length);
    };

    const callAjax = (payload) => {
        console.log("callAjax invoked with payload:", payload);
        addMessage({ role: 'user', content: payload.content });
        const userMessageIndex = chatContext.length;
        console.log("Added user message, index:", userMessageIndex);

        const wrappedPayload = [payload];

        window.chatbot_jsmo_module.callAI(wrappedPayload, (res) => {
            console.log("Received response from callAI:", res);
            if (res && res.content) {
                console.log("Valid response received:", res.content);
                updateMessage(res.content, userMessageIndex);
            } else {
                console.log("Unexpected response format:", res);
            }
        }, (err) => {
            console.log("callAI error", err);
        });
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext }}>
            {children}
        </ChatContext.Provider>
    );
};

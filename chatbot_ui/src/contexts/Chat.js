import React, { createContext, useState, useRef } from 'react';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children }) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);

    const updateApiContext = (newContext) => {
        apiContextRef.current = newContext;
        setApiContext(newContext);
    };

    const updateChatContext = (newContext) => {
        chatContextRef.current = newContext;
        setChatContext(newContext);
    };

    const addMessage = (message) => {
        const updatedApiContext = [
            ...apiContextRef.current,
            { role: message.role, content: message.content },
        ];
        updateApiContext(updatedApiContext);

        const newChatContext = [
            ...chatContextRef.current,
            {
                user_content: message.role === 'user' ? message.content : null,
                assistant_content: message.role === 'assistant' ? message.content : null,
                timestamp: new Date().getTime(),
            },
        ];
        updateChatContext(newChatContext);
    };

    const updateMessage = (response, index) => {
        const { response: assistantResponse, usage, id, model } = response;
        const updatedState = [...chatContextRef.current];
        updatedState[index] = {
            ...updatedState[index],
            assistant_content: assistantResponse.content,
            input_tokens: usage ? usage.prompt_tokens : null,
            output_tokens: usage ? usage.completion_tokens : null,
            input_cost: usage ? usage.input_cost : null,
            output_cost: usage ? usage.output_cost : null,
            id: id || null,
            model: model || null,
        };
        updateChatContext(updatedState);
        console.log("Updated chatContext after updateMessage:", updatedState);

        const updatedApiContext = [
            ...apiContextRef.current,
            { role: 'assistant', content: assistantResponse.content },
        ];
        updateApiContext(updatedApiContext);
    };

    const clearMessages = () => {
        setMsgCount(0);
        setSessionId(null);
        setMessages([]);
        updateChatContext([]);
        updateApiContext([]);
    };

    const replaceSession = (session) => {
        clearMessages();
        setSessionId(session.session_id);
        setMessages(session.queries);
        setMsgCount(session.queries.length);
    };

    const callAjax = (payload, callback) => {
        // Add user message to the contexts
        addMessage({ role: 'user', content: payload.content });

        // Calculate the index for the new message
        const userMessageIndex = chatContextRef.current.length - 1; // This is important!

        // Use the updated apiContext for the call
        const wrappedPayload = [...apiContextRef.current];
        console.log("calling callAI with ", wrappedPayload);

        window.chatbot_jsmo_module.callAI(wrappedPayload, (res) => {
            if (res && res.response) {
                console.log("Valid response received:", res);
                updateMessage(res, userMessageIndex);
                if (callback) callback();
            } else {
                console.log("Unexpected response format:", res);
            }
        }, (err) => {
            console.log("callAI error", err);
            if (callback) callback();
        });
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext }}>
            {children}
        </ChatContext.Provider>
    );
};

import React, { createContext, useState, useRef, useEffect } from 'react';
import { saveNewSession, updateSession, getSession, deleteSession } from '../components/database/dexie';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children }) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(Date.now().toString());
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);

    const updateApiContext = (newContext) => {
        apiContextRef.current = newContext;
        setApiContext(newContext);
    };

    const saveChatContext = async () => {
        if (sessionId) {
            const currentSession = await getSession(sessionId);
            if (currentSession) {
                await updateSession(sessionId, chatContextRef.current);
            } else {
                await saveNewSession(sessionId, Date.now(), chatContextRef.current);
            }
        }
    };

    const updateChatContext = async (newContext) => {
        chatContextRef.current = newContext;
        setChatContext(newContext);
        console.log("Updated chatContext:", newContext);
        await saveChatContext(); // Save chat session after each update
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

    const updateMessage = async (response, index) => {
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
        await updateChatContext(updatedState);

        const updatedApiContext = [
            ...apiContextRef.current,
            { role: 'assistant', content: assistantResponse.content },
        ];
        updateApiContext(updatedApiContext);
    };

    const clearMessages = async () => {
        setMsgCount(0);
        setSessionId(Date.now().toString()); // Generate a new session ID
        setMessages([]);
        updateChatContext([]);
        updateApiContext([]);
    };

    const replaceSession = async (session) => {
        await clearMessages();
        setSessionId(session.session_id);
        setMessages(session.queries);
        setMsgCount(session.queries.length);
        updateChatContext(session.queries);
    };

    const callAjax = (payload, callback) => {
        addMessage({ role: 'user', content: payload.content });

        const userMessageIndex = chatContextRef.current.length - 1;

        const wrappedPayload = [...apiContextRef.current];
        console.log("calling callAI with ", wrappedPayload);

        window.chatbot_jsmo_module.callAI(wrappedPayload, (res) => {
            if (res && res.response) {
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

    const updateVote = async (index, vote) => {
        const updatedState = [...chatContextRef.current];
        updatedState[index] = {
            ...updatedState[index],
            rating: vote
        };
        await updateChatContext(updatedState);
        console.log("Updated chatContext after vote:", updatedState);
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext, updateChatContext, updateVote }}>
            {children}
        </ChatContext.Provider>
    );
};

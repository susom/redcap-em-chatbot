import React, { createContext, useState, useRef, useEffect } from 'react';
import { saveNewSession, updateSession, getSession, deleteSession } from '../components/database/dexie';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children , projectContextRef}) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(Date.now().toString());
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);

    const DYNAMIC_CONTEXT_LABEL = "Active Project Context";

    useEffect(() => {
        console.log("apiContext updated: ", apiContext);
    }, [apiContext]);

    const updateApiContext = (newContext) => {
        apiContextRef.current = newContext;
        setApiContext(newContext);
    };

    const saveChatContext = async () => {
        if (sessionId && chatContextRef.current.length > 0) {
            const currentSession = await getSession(sessionId);
            if (currentSession) {
                await updateSession(sessionId, chatContextRef.current);
            } else {
                await saveNewSession(sessionId, Date.now(), chatContextRef.current);
            }
        }
    };

    const updateChatContext = async (newContext, shouldSave = true) => {
        chatContextRef.current = newContext;
        setChatContext(newContext);
        // console.log("Updated chatContext:", newContext);
        if (shouldSave) {
            await saveChatContext(); // Save chat session after each update
        }
    };

    const addMessage = (message) => {
        let updatedApiContext;
    
        // If system context
        if (message.role === "system" && message.content.startsWith(DYNAMIC_CONTEXT_LABEL)) {
            // Remove any previous system context, keep user/assistant turns
            updatedApiContext = apiContextRef.current.filter(
                entry => !(entry.role === "system" && entry.content.startsWith(DYNAMIC_CONTEXT_LABEL))
            );
            // Insert new system context at the top
            updatedApiContext = [{ role: "system", content: message.content, index: 0 }, ...updatedApiContext];
            updateApiContext(updatedApiContext);
            return;
        }
    
        // Otherwise, add the message after the system context (if present)
        const systemContext = apiContextRef.current.find(
            entry => entry.role === "system" && entry.content.startsWith(DYNAMIC_CONTEXT_LABEL)
        );
        const userAssistantTurns = apiContextRef.current.filter(
            entry => !(entry.role === "system" && entry.content.startsWith(DYNAMIC_CONTEXT_LABEL))
        );
    
        // Append new turn
        updatedApiContext = [
            ...(systemContext ? [systemContext] : []),
            ...userAssistantTurns,
            { role: message.role, content: message.content, index: userAssistantTurns.length },
        ];
        updateApiContext(updatedApiContext);
    
        // Only update chatContext for user/assistant, not system messages
        if (message.role === "user" || message.role === "assistant") {
            const newChatContext = [
                ...chatContextRef.current,
                {
                    user_content: message.role === 'user' ? message.content : null,
                    assistant_content: message.role === 'assistant' ? message.content : null,
                    timestamp: new Date().getTime(),
                },
            ];
            updateChatContext(newChatContext);
        }
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
            { role: 'assistant', content: assistantResponse.content, index },
        ];
        updateApiContext(updatedApiContext);
    };

    const clearMessages = async () => {
        const newSessionId = Date.now().toString(); // Generate a new session ID
        setMsgCount(0);
        setMessages([]);
        setSessionId(newSessionId);

        // Filter apiContext to keep only "system" roles
        const filteredApiContext = apiContextRef.current.filter(entry => entry.role === "system");
        chatContextRef.current = [];
        apiContextRef.current = filteredApiContext;

        setChatContext([]);
        setApiContext(filteredApiContext);
    };

    const replaceSession = async (session) => {
        setSessionId(session.session_id);
        setMessages(session.queries);
        setMsgCount(session.queries.length);
        updateChatContext(session.queries, false);
    };

    const callAjax = (payload, callback) => {
        const initialSystemContextArray = window.chatbot_jsmo_module.getInitialSystemContext();
        if (Array.isArray(initialSystemContextArray) && initialSystemContextArray.length > 0) {
            const initial_system_context = initialSystemContextArray.pop();
            console.log("Initial API context is empty. Injecting system context before first query:", initial_system_context);
            addMessage(initial_system_context);
        } else {
            console.log("No initial system context available.");
            // You can decide to proceed without it or handle this case as needed.
        }

        // Inject project context system message if present
        if (projectContextRef && projectContextRef.current) {
            addMessage({ role: "system", content: DYNAMIC_CONTEXT_LABEL + ":\n" + projectContextRef.current });
        }
        
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
        // console.log("Updated chatContext after vote:", updatedState);
    };

    const deleteInteraction = async (index) => {
        const updatedChatContext = [...chatContextRef.current];
        updatedChatContext.splice(index, 1);

        const updatedApiContext = apiContextRef.current.filter(entry => entry.index !== index);

        // Update the index of remaining entries
        updatedApiContext.forEach((entry, i) => {
            if (entry.index > index) {
                entry.index -= 1;
            }
        });

        updateChatContext(updatedChatContext);
        updateApiContext(updatedApiContext);
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext, updateChatContext, updateVote, deleteInteraction }}>
            {children}
        </ChatContext.Provider>
    );
};

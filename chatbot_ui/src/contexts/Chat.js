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
    const [errorMessage, setErrorMessage] = useState(null);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);

    const DYNAMIC_CONTEXT_LABEL = "Active Project Context";
    const injectedUsername = useRef(false);

    // useEffect(() => {
    //     console.log("apiContext updated: ", apiContext);
    // }, [apiContext]);

    //ACCEPT POST MESSAGE FROM OUTSIDE TO INITIATE CAPPY MESSAGES!
    useEffect(() => {
        const handleInitiate = (event) => {
            if (event.data?.type === 'cappy-initiate') {
                const payload = {
                    content: event.data.text,
                    meta: event.data.meta || { internal: true }
                };
    
                // Inject internal user message
                addMessage({ role: 'user', content: payload.content, meta: payload.meta });
                callAjax(payload, null, true);
            }
        };
    
        window.addEventListener('message', handleInitiate);
        return () => window.removeEventListener('message', handleInitiate);
    }, []);

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
            { role: message.role, content: message.content, index: userAssistantTurns.length, meta: message.meta ?? undefined  }
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
                    meta: message.meta ?? undefined 
                },
            ];
            updateChatContext(newChatContext);
        }
    };

    const updateMessage = async (response, index) => {
        const { response: assistantResponse, usage, id, model, tools_used } = response;
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
            tools_used: tools_used || null,
        };
        await updateChatContext(updatedState);

        const updatedApiContext = [
            ...apiContextRef.current,
            { role: 'assistant', content: assistantResponse.content, index, meta: response.meta ?? undefined  },
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

    const callAjax = (payload, callback, skipAddMessage = false) => {
        if (!injectedUsername.current && window.cappy_project_config?.current_user) {
            console.log("Injecting username message...", window.cappy_project_config?.current_user);
            addMessage({
                role: "user",
                content: `My name is ${window.cappy_project_config.current_user}. Please call me that in this chat.`,
                meta: { internal: true }
            });
            injectedUsername.current = true;
        }

        // Inject project context system message if present
        if (projectContextRef && projectContextRef.current) {
            addMessage({ role: "system", content: DYNAMIC_CONTEXT_LABEL + ":\n" + projectContextRef.current });
        }
        
        if (!skipAddMessage) {
            addMessage({ role: 'user', content: payload.content, meta: payload.meta });
        }

        const userMessageIndex = chatContextRef.current.length - 1;
        const wrappedPayload = [...apiContextRef.current];
        console.log("calling callAI with ", wrappedPayload);

        window.chatbot_jsmo_module.callAI(wrappedPayload, (res) => {
            if (res && res.response) {
                if (payload.meta?.internal) {
                    // Inject only assistant message for internal triggers
                    addMessage({ role: 'assistant', content: res.response.content });
                } else {
                    updateMessage(res, userMessageIndex);
                }
                if (callback) callback();
            } else {
                console.log("Unexpected response format:", res);
                setErrorMessage("I received an unexpected response. Please try again.");
                if (callback) callback();
            }
        }, (err) => {
            console.log("callAI error", err);
            setErrorMessage("I'm having trouble connecting right now. Please wait a moment and try again.");
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

    const clearError = () => {
        setErrorMessage(null);
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext, updateChatContext, updateVote, deleteInteraction, errorMessage, clearError }}>
            {children}
        </ChatContext.Provider>
    );
};

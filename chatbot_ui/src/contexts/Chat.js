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
    const CONVERSATION_SUMMARY_LABEL = "Previous Conversation Summary";
    const injectedUsername = useRef(false);

    // Context compression settings
    const COMPRESSION_THRESHOLD = 20; // Trigger compression after 20 messages
    const KEEP_RECENT_MESSAGES = 6;   // Keep last 6 messages (3 Q&A pairs)
    const SUMMARIZATION_MODEL = 'deepseek'; // Cheap model for summaries

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

    /**
     * Check if context compression is needed
     */
    const needsCompression = (context) => {
        const userAssistantTurns = context.filter(m => m.role !== 'system');
        return userAssistantTurns.length > COMPRESSION_THRESHOLD;
    };

    /**
     * Compress context by summarizing old turns
     * Returns compressed apiContext with summary injected
     */
    const compressContext = async (context) => {
        console.log("🗜️ Context compression triggered");

        // Separate system messages, old turns, recent turns
        const systemMessages = context.filter(m => m.role === 'system');
        const userAssistantTurns = context.filter(m => m.role !== 'system');

        if (userAssistantTurns.length <= KEEP_RECENT_MESSAGES) {
            return context; // Nothing to compress
        }

        const oldTurns = userAssistantTurns.slice(0, -KEEP_RECENT_MESSAGES);
        const recentTurns = userAssistantTurns.slice(-KEEP_RECENT_MESSAGES);

        console.log(`📦 Compressing ${oldTurns.length} old messages, keeping ${recentTurns.length} recent`);

        // Check if we already have a summary - if so, include it in what we're summarizing
        const existingSummary = systemMessages.find(m => m.content.startsWith(CONVERSATION_SUMMARY_LABEL));
        const projectContext = systemMessages.find(m => m.content.startsWith(DYNAMIC_CONTEXT_LABEL));

        // Build text to summarize
        let textToSummarize = '';
        if (existingSummary) {
            textToSummarize += existingSummary.content + '\n\n';
        }
        textToSummarize += oldTurns.map(m => `${m.role}: ${m.content}`).join('\n\n');

        // Call SecureChatAI to summarize
        const summaryPrompt = `Concisely summarize this conversation in 3-5 sentences. Preserve key facts, decisions, and context. Be brief but comprehensive.\n\n${textToSummarize}`;

        try {
            const summaryResponse = await new Promise((resolve, reject) => {
                window.chatbot_jsmo_module.callAI(
                    [{ role: 'user', content: summaryPrompt }],
                    (res) => {
                        if (res && res.response) {
                            resolve(res.response.content);
                        } else {
                            reject(new Error('Invalid summary response'));
                        }
                    },
                    (err) => reject(err)
                );
            });

            console.log("✅ Summary generated:", summaryResponse.substring(0, 100) + '...');

            // Rebuild context with summary
            const compressedContext = [
                ...(projectContext ? [projectContext] : []),
                {
                    role: 'system',
                    content: `${CONVERSATION_SUMMARY_LABEL}:\n${summaryResponse}`
                },
                ...recentTurns
            ];

            return compressedContext;

        } catch (err) {
            console.error("❌ Failed to generate summary:", err);
            // Fallback: just keep recent messages without summary
            return [
                ...(projectContext ? [projectContext] : []),
                ...recentTurns
            ];
        }
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

        // Check if compression is needed before sending
        const handleCompressedCall = async () => {
            let contextToSend = [...apiContextRef.current];

            if (needsCompression(contextToSend)) {
                console.log("🗜️ Compressing context before AI call...");
                contextToSend = await compressContext(contextToSend);
                // Update apiContext with compressed version
                updateApiContext(contextToSend);
            }

            console.log("calling callAI with ", contextToSend);

            window.chatbot_jsmo_module.callAI(contextToSend, (res) => {
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

        // Execute the async compression + AI call
        handleCompressedCall();
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

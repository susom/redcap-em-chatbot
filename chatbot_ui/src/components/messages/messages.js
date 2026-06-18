import React, { useContext, useRef, useEffect } from "react";
import { Overlay, Popover } from 'react-bootstrap';
import ReactMarkdown from "react-markdown";
import { HandThumbsUp, HandThumbsDown, HandThumbsUpFill, HandThumbsDownFill, XCircleFill, CircleFill } from 'react-bootstrap-icons';
import { ChatContext } from "../../contexts/Chat";
import "./messages.css";

export const Messages = () => {
    const chat_context = useContext(ChatContext);
    const newQaRef = useRef(null);

    const introText = window.cappy_project_config?.intro || "Hi I am Cappy! Your REDCap Support buddy. How can I assist you today?";
    // When a preemptive greeting is configured, Cappy greets proactively, so
    // suppress the empty-state intro text to avoid clutter.
    const hasInitiator = !!(window.cappy_project_config?.chat_initiator || '').trim();

    // Convert bullet points to proper markdown format
    const formatMarkdown = (text) => {
        if (!text) return text;

        // Convert "• item" patterns to markdown list items with line breaks
        // This handles both unicode bullet (•) and asterisk (*)
        let formatted = text.replace(/([•*])\s+/g, '\n- ');

        // Clean up any leading line breaks
        formatted = formatted.replace(/^\n+/, '');

        return formatted;
    };

    const handleClick = (vote, index) => {
        chat_context.updateVote(index, vote);
    };

    const handleDelete = (index) => {
        chat_context.deleteInteraction(index);
    };

    const getVotesElement = () => {
        if (newQaRef.current) {
            return newQaRef.current.querySelector('.votes');
        }
        return null;
    };

    const popoverOverlay = (
        <Overlay target={getVotesElement} show={chat_context.showRatingPO} placement="top">
            <Popover id="popover-example">
                <Popover.Header as="h3">Please Rate The Response</Popover.Header>
                <Popover.Body>
                    The feedback helps us tune our support bot.
                </Popover.Body>
            </Popover>
        </Overlay>
    );

    useEffect(() => {
        if (newQaRef.current) {
            newQaRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [chat_context.chatContext]);

    const visibleMessages = chat_context.chatContext.filter(m => m?.meta?.internal !== true);
    const getToolNames = (toolsUsed) => {
        if (!Array.isArray(toolsUsed)) return [];
        return toolsUsed.map(t => t?.name).filter(Boolean);
    };

    return (
        <div className={`messages`}>
            {visibleMessages.length > 0 ? (
                visibleMessages.map((message, index) => (
                    <React.Fragment key={index}>
                        <dl ref={index === visibleMessages.length - 1 ? newQaRef : null}>
                            {message.user_content?.trim() && (
                                <dt>
                                    {message.user_content}
                                    <XCircleFill className="delete-icon" onClick={() => handleDelete(index)} />
                                </dt>
                            )}
                            {message.assistant_content && (() => {
                                const toolNames = getToolNames(message.tools_used);
                                const hasEscalationTool = toolNames.some(name => name.startsWith('escalation.'));
                                return (
                                <dd
                                    className={`${!message.user_content?.trim() ? 'extratop_margin' : ''} ${hasEscalationTool ? 'has-escalation-tool' : ''}`}
                                    data-tools={toolNames.join(',')}
                                    data-has-escalation={hasEscalationTool ? 'true' : 'false'}
                                >
                                    {toolNames.length > 0 && (
                                        <span className="tool-markers" aria-hidden="true">
                                            {toolNames.map((name) => (
                                                <span key={name} className="tool-marker" data-tool={name} />
                                            ))}
                                        </span>
                                    )}
                                    <ReactMarkdown
                                        components={{
                                            a: ({node, children, ...props}) => (
                                                <a {...props} target="_blank" rel="noopener noreferrer">
                                                    {children}
                                                </a>
                                            )
                                        }}
                                    >
                                        {formatMarkdown(message.assistant_content)}
                                    </ReactMarkdown>
                                    {toolNames.length > 0 && (
                                        <div className="tool-usage">
                                            Used tools: {toolNames.join(', ')}
                                        </div>
                                    )}
                                    {!window.cappy_project_config?.hide_message_meta && (
                                        <div className={'msg_meta'}>
                                            <div className={'token_usage'}>
                                                <div>Input Tokens: {message.input_tokens}</div>
                                                <div>Output Tokens: {message.output_tokens}</div>
                                            </div>
                                            <div className={`votes`}>
                                                {chat_context.showRatingPO ? popoverOverlay : ""}
                                                <div className={`vote up`} onClick={() => { handleClick(1, index) }}>
                                                    {message.rating === 1
                                                        ? <HandThumbsUpFill color="#ccc" size={20} />
                                                        : <HandThumbsUp color="#ccc" size={20} />}
                                                </div>
                                                <div className={`vote down`} onClick={() => { handleClick(0, index) }}>
                                                    {message.rating === 0
                                                        ? <HandThumbsDownFill color="#ccc" size={20} />
                                                        : <HandThumbsDown color="#ccc" size={20} />}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </dd>
                                );
                            })()}
                        </dl>
                        {index < visibleMessages.length - 1 && <hr className="divider" />}
                    </React.Fragment>
                ))
            ) : (
                !hasInitiator && <p className={`empty`}><em className={`soft_text`}>{introText}</em></p>
            )}
            {chat_context.loading && (
                <dl className="loading-dl">
                    <dd className="extratop_margin">
                        <div className="loading-ellipsis">
                            <CircleFill className="pulse" size={5} />
                            <CircleFill className="pulse" size={5} />
                            <CircleFill className="pulse" size={5} />
                        </div>
                    </dd>
                </dl>
            )}
        </div>
    );
};

export default Messages;

import React, { useContext, useRef, useEffect, useState } from "react";
import { Overlay, Popover } from 'react-bootstrap';
import { HandThumbsUp, HandThumbsDown, HandThumbsUpFill, HandThumbsDownFill } from 'react-bootstrap-icons';
import { ChatContext } from "../../contexts/Chat";
import axios from "axios";
import "./messages.css";

export const Messages = () => {
    const chat_context = useContext(ChatContext);
    const newQaRef = useRef(null);
    const [chatThumbs, setChatThumbs] = useState({});
    const new_thumb_obj = {
        "up_hover": 0,
        "down_hover": 0,
    };

    const addChatThumb = (session_id, value_obj) => {
        setChatThumbs(prevState => ({
            ...prevState,
            [session_id]: value_obj,
        }));
    };

    const updateChatThumb = (session_id, propKey, newVal) => {
        setChatThumbs(prevState => ({
            ...prevState,
            [session_id]: {
                ...prevState[session_id],
                [propKey]: newVal,
            },
        }));
    };

    const handleMouseEnterLeave = (enter, up_thumb, session_id) => {
        if (!chatThumbs.hasOwnProperty(session_id)) {
            addChatThumb(session_id, new_thumb_obj);
        }

        const hover_value = enter ? 1 : 0;
        if (up_thumb) {
            updateChatThumb(session_id, "up_hover", hover_value);
        } else {
            updateChatThumb(session_id, "down_hover", hover_value);
        }
    };

    const handleClick = async (vote, storage_obj) => {
        try {
            storage_obj["rating"] = vote;
            const result = await axios.post(`${process.env.REACT_APP_BACKEND_URL}/rate`, { "storage_obj": storage_obj });
            if (result.data.success === 1) {
                // Removing updateSession since it's not defined and not needed for MVP
                // updateSession(chat_context.sessionId, chat_context.chatContext);
            }
        } catch (error) {
            console.error("Error:", error);
        }
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

    return (
        <div className={`messages`}>
            {
                chat_context && chat_context.chatContext && chat_context.chatContext.length > 0
                    ? (
                        chat_context.chatContext.map((message, index) => (
                            <React.Fragment key={index}>
                                <dl ref={index === chat_context.chatContext.length - 1 ? newQaRef : null}>
                                    <dt>{message.user_content}</dt>
                                    {message.assistant_content && (
                                        <dd>
                                            {message.assistant_content}
                                            <div className={'msg_meta'}>
                                                <div className={'token_usage'}>
                                                    <div>Input Tokens: {message.input_tokens}</div>
                                                    <div>Output Tokens: {message.output_tokens}</div>
                                                </div>
                                                <div className={`votes`}>
                                                    {chat_context.showRatingPO ? popoverOverlay : ""}
                                                    <div className={`vote up`} onMouseEnter={() => { handleMouseEnterLeave(true, true, message.id) }} onMouseLeave={() => { handleMouseEnterLeave(false, true, message.id) }} onClick={() => { handleClick(1, message) }}>
                                                        {chatThumbs.hasOwnProperty(message.id) && (chatThumbs[message.id]["up_hover"] || message.rating === 1) ? (<HandThumbsUpFill color="#ccc" size={20}/>) : (<HandThumbsUp color="#ccc" size={20}/>)}
                                                    </div>
                                                    <div className={`vote down`} onMouseEnter={() => { handleMouseEnterLeave(true, false, message.id) }} onMouseLeave={() => { handleMouseEnterLeave(false, false, message.id) }} onClick={() => { handleClick(0, message) }}>
                                                        {chatThumbs.hasOwnProperty(message.id) && (chatThumbs[message.id]["down_hover"] || message.rating === 0) ? (<HandThumbsDownFill color="#ccc" size={20}/>) : (<HandThumbsDown color="#ccc" size={20}/>)}
                                                    </div>
                                                </div>
                                            </div>
                                        </dd>
                                    )}
                                </dl>
                                {index < chat_context.chatContext.length - 1 && <hr className="divider" />}
                            </React.Fragment>
                        ))
                    )
                    : (<p className={`empty`}><em className={`soft_text`}>Ask me anything!</em></p>)
            }
        </div>
    );
};

export default Messages;

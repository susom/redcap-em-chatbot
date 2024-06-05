import React, { useContext, useState, useEffect, useRef} from "react";
import axios from "axios";
import { Overlay, Popover } from 'react-bootstrap';
import { HandThumbsUp, HandThumbsDown, HandThumbsUpFill, HandThumbsDownFill } from 'react-bootstrap-icons';
import { ChatContext } from "../../contexts/Chat";
import {updateSession} from "../database/dexie";

import "./messages.css";

export const Messages = ({ messages }) => {
    const chat_context = useContext(ChatContext);
    const [chatThumbs, setChatThumbs] = useState({});

    const cost_per_token    = .000002;

    const newQaRef          = useRef(null);
    const new_thumb_obj     = {
        "up_hover" : 0,
        "down_hover" : 0,
    };


    useEffect(() => {
        chat_context.messages.forEach((msg) => {
            addChatThumb(msg.id, new_thumb_obj);
          });

        if (newQaRef.current) {
            newQaRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [chat_context.messages]);

    useEffect(() => {
        document.addEventListener('mousedown', handleOutsideClick);
        return () => {
            document.removeEventListener('mousedown', handleOutsideClick);
        };
    }, [chat_context.showRatingPO]);

    const handleOutsideClick = (event) => {
        if ( chat_context.showRatingPO ) {
            chat_context.setShowRatingPO(false);
        }
    };

    //Only handles hover on and off state for UI
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

    function handleMouseEnterLeave(enter, up_thumb, session_id) {
        if(!chatThumbs.hasOwnProperty(session_id)){
            addChatThumb(session_id, new_thumb_obj);
        }

        if(up_thumb){
            //up thumb
            const hover_value = enter ? 1 : 0;
            updateChatThumb(session_id, "up_hover", hover_value);
        }else{
            //down thumb
            const hover_value = enter ? 1 : 0;
            updateChatThumb(session_id, "down_hover", hover_value);
        }
    }

    //vote action , save storage object + vote TO Firestore
    const handleClick = async (vote, storage_obj) => {
        try{
            storage_obj["rating"] = vote;
            const result = await axios.post(`${process.env.REACT_APP_BACKEND_URL}/rate`, { "storage_obj" : storage_obj });
            if(result.data.success === 1){
                updateSession(chat_context.sessionId, chat_context.messages);
            }
        }catch(error){
            console.error("Error:", error);
        }
    }

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

    return (
            <div className={`messages`}>
            {
                chat_context.messages.length > 0
                    ?  (
                          chat_context.messages.map((message, index) => (

                            <dl key={index}
                                ref={index === chat_context.messages.length - 1 ? newQaRef : null}
                            >
                                <dt>{message.q}</dt>
                                <dd>{message.a}
                                    <div className={'msg_meta'}>
                                        <div className={`token_usage`}>
                                            <span><i>Tokens Used:</i> <b>{message.firestore.usage.total_tokens}</b></span>
                                        </div>
                                        <div className={`votes`}>
                                            {
                                                chat_context.showRatingPO
                                                ? popoverOverlay
                                                : ""
                                            }
                                            <div className={`vote up`}
                                                onMouseEnter={(e) => {
                                                    handleMouseEnterLeave(1, 1, message.firestore.id)
                                                }}
                                                onMouseLeave={(e) => {
                                                    handleMouseEnterLeave(0, 1, message.firestore.id)
                                                }}
                                                onClick={(e) => {
                                                    handleClick(1,message.firestore)
                                                }}>
                                                {
                                                    chatThumbs.hasOwnProperty(message.firestore.id) && (chatThumbs[message.firestore.id]["up_hover"] || message.firestore["rating"] === 1)
                                                        ? (<HandThumbsUpFill color="#ccc" size={20}/>)
                                                        : (<HandThumbsUp color="#ccc" size={20}/>)
                                                }
                                            </div>
                                            <div className={`vote down`}
                                                onMouseEnter={(e) => {
                                                    handleMouseEnterLeave(1, 0, message.firestore.id)
                                                }}
                                                onMouseLeave={(e) => {
                                                    handleMouseEnterLeave(0, 0, message.firestore.id)
                                                }}
                                                onClick={(e) => {
                                                    handleClick(0,message.firestore)
                                                }}>
                                                {
                                                    chatThumbs.hasOwnProperty(message.firestore.id) && (chatThumbs[message.firestore.id]["down_hover"] || message.firestore["rating"] === 0)
                                                        ? (<HandThumbsDownFill color="#ccc" size={20}/>)
                                                        : (<HandThumbsDown color="#ccc" size={20}/>)
                                                }
                                            </div>
                                        </div>
                                    </div>
                                </dd>
                            </dl>
                          ))
                        )
                    :   (<p className={`empty`}><em className={`soft_text`}>Ask me anything!</em></p>)

            }
            </div>
            );
};

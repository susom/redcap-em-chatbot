import React, { useEffect, useState, useContext } from "react";
import axios from "axios";

import {v4 as uuid} from "uuid";
import {saveNewSession, addSessionQuery} from "../database/dexie";

import {ChatContext} from "../../contexts/Chat";

import { Container } from 'react-bootstrap';
import { Send, ArrowClockwise, EraserFill } from 'react-bootstrap-icons';

import "./footer.css";

function Footer ({changeView}){
    const [inputPH, setInputPH] = useState("Ask a question...");
    const [input, setInput]     = useState("");
    const [loading, setLoading] = useState(false);
    const chat_context          = useContext(ChatContext);

    useEffect(() =>{
        callAjax("useEffect in the footer");
    })

    const findMostRecentUpVoted = (arr) => {
        for (let i = arr.length - 1; i >= 0; i--) {
            if (Object.hasOwn(arr[i].firestore, "rating") && arr[i].firestore.rating) {
                return arr[i];
            } else {
                continue;
            }
        }

        return null;
    }

    const clearCurrent = () => {
        chat_context.clearMessages();
    }


    const callAjax = (payload, actionType="callAI") => {
        console.log("does it get module from outside?", window.chatbot_jsmo_module);
        window.chatbot_jsmo_module.TestAction(payload, (res) => {
            if (res) {
                console.log("TestAction called from inside react chatbot app")
            }
        }, (err) => {
            console.log("getParticipants error");
        });
    }

    // Mock function to simulate backend response
    const mockBackendCall = (input, last_qa) => {
        return new Promise((resolve) => {
            setTimeout(() => {
                const randomLoremIpsum = "Lorem ipsum dolor sit amet, consectetur adipiscing elit.";
                const firestoreData = { id: uuid(), usage: { total_tokens: 50 }, literal_prompt: "mock_prompt" };
                resolve({ response: randomLoremIpsum, firestore_data: firestoreData });
            }, 1000);
        });
    };


    const handleSubmit = async () => {
        try {
            setLoading(true);

            const has_unrated = chat_context.messages.some(item => !Object.hasOwn(item.firestore, 'rating') );
            if(has_unrated){
                chat_context.setShowRatingPO(true);
                setLoading(false);
                return;
            }

            const last_qa   = findMostRecentUpVoted(chat_context.messages);
            // const last_qa   = chat_context.messages.length ? chat_context.messages[chat_context.messages.length - 1] : null;
            const post_data = {
                "user_input"    : input,
                "prev_input"    : last_qa ? last_qa.q : undefined,
                "prev_response" : last_qa ? last_qa.a : undefined,
                "prev_prompt"   : last_qa ? last_qa.firestore.literal_prompt : undefined
            };

            //POST USER INPUT TO BACKEND ENDPOINT
            // const result = await axios.post(`${process.env.REACT_APP_BACKEND_URL}/chat`, post_data);
            const result = await mockBackendCall(input, last_qa);

            //MAKE ONE UNIT OF A Q&A TO SAVE IN THE SESSION
            const q_a = {"q" : input , "a" : result.response, "id" : result.firestore_data.id, "firestore" : result.firestore_data };
            const for_archive = Object.assign({}, q_a);

            //SAVE TO INDEX DB
            if(!chat_context.sessionId){
                const new_unique_id = uuid();
                const first_timestamp = new Date().getTime();
                chat_context.setSessionId(new_unique_id);
                saveNewSession(new_unique_id, first_timestamp, [for_archive]);
            }else{
                addSessionQuery(chat_context.sessionId, for_archive);
            }

            //CURRENT SESSIONS MESSAGES
            chat_context.addMessage(q_a);
            chat_context.setMsgCount(chat_context.messages.length+1);

            //CLEAR INPUT AND LOADING
            setInput("");
            setLoading(false);

            // TODO , should i concatanate the User inputs so that they might carry the context throughout the chat?
            // MAYBE ONCE TRAIN CUSTOM MODEL THEN CAN PRESERVE TOKENS

        } catch (error) {
            console.error("Error:", error);
        }

        // changeView('home'); //i think i want to navigate home?  not sure yet
    };

    const handleKeyDown = (e) => {
        e.preventDefault();
        if (e.key === 'Enter') {
            setInput(e.target.value);
            handleSubmit();
        }
    }

    return (
                <Container className={`container footer`}>
                    <button onClick={clearCurrent} className={`clear_chat`}><EraserFill color="#ccc" size={20}/></button>
                    <input className={`user_input`} placeholder={inputPH} value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={  handleKeyDown } />
                    <button onClick={handleSubmit}><Send color="#ccc" size={20} className={`send ${loading ? "off" : ""}`}/><ArrowClockwise color="#ccc" size={20} className={`sendfill ${loading ? "rotate" : ""}`}/></button>
                </Container>
            );
}

export default Footer;

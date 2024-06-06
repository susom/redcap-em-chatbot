import React, { useState, useEffect, useContext } from "react";
import { ChatContext } from "../../contexts/Chat";
import { Container } from 'react-bootstrap';
import { Send, ArrowClockwise, EraserFill } from 'react-bootstrap-icons';
import "./footer.css";

function Footer({ changeView }) {
    const chat_context = useContext(ChatContext);
    const [inputPH, setInputPH] = useState("Ask a question...");
    const [input, setInput] = useState("");
    const [loading, setLoading] = useState(false);

    const handleSubmit = () => {
        if (input.trim() === "") return;
        setLoading(true);
        chat_context.callAjax({ role: 'user', content: input }, () => setLoading(false));
        setInput(""); // Clear input field
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleSubmit();
        }
    };

    useEffect(() => {
        if (!loading) {
            setLoading(false);
        }
    }, [chat_context.chatContext]);

    return (
        <Container className={`container footer`}>
            <button onClick={chat_context.clearMessages} className={`clear_chat`}><EraserFill color="#ccc" size={20} /></button>
            <input className={`user_input`} placeholder={inputPH} value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={handleKeyDown} />
            <button onClick={handleSubmit}><Send color="#ccc" size={20} className={`send ${loading ? "off" : ""}`} /><ArrowClockwise color="#ccc" size={20} className={`sendfill ${loading ? "rotate" : ""}`} /></button>
        </Container>
    );
}

export default Footer;

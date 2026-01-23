import React, { useState, useContext, useEffect, useRef } from "react";
import { ChatContext } from "../../contexts/Chat";
import { Container } from 'react-bootstrap';
import { Send, ArrowClockwise, EraserFill } from 'react-bootstrap-icons';
import "./footer.css";

function Footer({ changeView }) {
    const chat_context = useContext(ChatContext);
    const [input, setInput] = useState("");
    const inputRef = useRef(null);

    const handleSubmit = () => {
        if (input.trim() === "") return;

        chat_context.callAjax({ role: 'user', content: input });
        setInput(""); // Clear input field
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleSubmit();
        }
    };

    useEffect(() => {
     if (!chat_context.loading) {
       inputRef.current && inputRef.current.focus();
     }
   }, [chat_context.loading]);

    return (
        <Container className="footer">
            <div className="left-group">
                <button onClick={chat_context.clearMessages} className="clear_chat">
                <EraserFill color="#ccc" size={20} />
                </button>
                <input
                    className="user_input"
                    placeholder="Ask a question..."
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    onKeyDown={handleKeyDown}
                    disabled={chat_context.loading}
                    ref={inputRef}
                />
            </div>
            <button onClick={handleSubmit}>
                <Send color="#ccc" size={20} className={`send ${chat_context.loading ? "off" : ""}`} />
                <ArrowClockwise color="#ccc" size={20} className={`sendfill ${chat_context.loading ? "rotate" : ""}`} />
            </button>
        </Container>

    );
}

export default Footer;

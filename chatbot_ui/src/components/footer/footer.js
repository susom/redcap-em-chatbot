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

    // The chat widget is injected inside REDCap's page <form>, so a plain
    // onKeyDown + preventDefault on Enter is unreliable for stopping the host
    // page's implicit form submission. Wrapping input + send button in a
    // <form onSubmit> is the canonical React pattern: Enter on the input fires
    // submit on THIS form, the Send button is type="submit", and we
    // preventDefault on the form event so nothing bubbles to the page form.
    const handleFormSubmit = (e) => {
        e.preventDefault();
        handleSubmit();
    };

    useEffect(() => {
     if (!chat_context.loading) {
       inputRef.current && inputRef.current.focus();
     }
   }, [chat_context.loading]);

    // REDCap's DataEntry.js attaches a native jQuery keydown handler to
    // EVERY input on the page that calls dataEntrySubmit() on Enter, which
    // submits the host REDCap form. That handler runs in the target phase
    // BEFORE React's delegated onKeyDown reaches the bubble phase, so a
    // plain <form onSubmit> preventDefault is too late.
    //
    // We intercept at the CAPTURE phase on document — capture fires before
    // target. If the Enter happens inside our chat, stopPropagation so
    // REDCap's handler never sees the event, then submit the chat ourselves.
    //
    // We read the input value directly from the DOM (NOT from React state)
    // because this listener is attached once at mount with an empty-deps
    // effect, so a state closure would be stale on every subsequent render.
    useEffect(() => {
        function captureKeydown(e) {
            if (e.key !== 'Enter') return;
            var t = e.target;
            if (!t || !t.closest || !t.closest('#chatbot_ui_container')) return;
            if (t.tagName !== 'INPUT' || (t.type && t.type !== 'text')) return;
            var value = t.value || '';
            if (!value.trim()) return;
            e.stopPropagation();
            e.preventDefault();
            chat_context.callAjax({ role: 'user', content: value });
            t.value = '';
            setInput('');
        }
        document.addEventListener('keydown', captureKeydown, true);
        return () => document.removeEventListener('keydown', captureKeydown, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <Container className="footer">
            <form className="cappy-chat-form" onSubmit={handleFormSubmit}>
                <div className="left-group">
                    <button type="button" onClick={chat_context.clearMessages} className="clear_chat">
                    <EraserFill color="#ccc" size={20} />
                    </button>
                    <input
                        className="user_input"
                        placeholder="Ask a question..."
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        disabled={chat_context.loading}
                        ref={inputRef}
                    />
                </div>
                <button type="submit">
                    <Send color="#ccc" size={20} className={`send ${chat_context.loading ? "off" : ""}`} />
                    <ArrowClockwise color="#ccc" size={20} className={`sendfill ${chat_context.loading ? "rotate" : ""}`} />
                </button>
            </form>
        </Container>

    );
}

export default Footer;

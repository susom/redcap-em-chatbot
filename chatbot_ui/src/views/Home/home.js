import React, { useContext } from "react";
import { Container, Toast, ToastContainer } from 'react-bootstrap';
import { Messages } from "../../components/messages/messages";
import { ChatContext } from "../../contexts/Chat";

export function Home(){
    const chat_context = useContext(ChatContext);

    return (
                <Container className={`body`}>
                    <ToastContainer position="top-center" className="p-3" style={{position: 'absolute', zIndex: 9999}}>
                        <Toast
                            show={!!chat_context.errorMessage}
                            onClose={chat_context.clearError}
                            delay={5000}
                            autohide
                            bg="danger"
                        >
                            <Toast.Header closeButton>
                                <strong className="me-auto">Error</strong>
                            </Toast.Header>
                            <Toast.Body className="text-white">{chat_context.errorMessage}</Toast.Body>
                        </Toast>
                    </ToastContainer>
                    <Messages/>
                </Container>
            );
}
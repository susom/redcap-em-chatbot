import React, { useState, useContext, useEffect } from "react";
import { Container, Row, Col } from 'react-bootstrap';
import { Trash } from 'react-bootstrap-icons';

import { confirmAlert } from 'react-confirm-alert';
import 'react-confirm-alert/src/react-confirm-alert.css';

import { ChatContext } from "../../contexts/Chat";

import { getAllSessions, deleteSession, deleteAllData } from "../../components/database/dexie";
import { formatTimestamp, truncateString } from "../../components/utils/utils";

export function History({ changeView }) {
    const [sessions, setSessions] = useState([]);
    const chat_context = useContext(ChatContext);

    useEffect(() => {
        getAllSessions().then((sessions) => {
            setSessions(sessions.sort((a, b) => b.timestamp - a.timestamp)); // Sort by timestamp in reverse chronological order
        });
    }, []);

    const handleDelete = async (sessionId) => {
        await deleteSession(sessionId);
        const updatedSessions = sessions.filter(session => session.session_id !== sessionId);
        setSessions(updatedSessions);
    }

    const handleDeleteAll = async () => {
        confirmAlert({
            title: 'Confirm deletion',
            message: 'Are you sure you want to delete all chat history?',
            buttons: [
                {
                    label: 'Yes',
                    onClick: async () => {
                        await deleteAllData();
                        setSessions([]);
                        chat_context.clearMessages();
                    }
                },
                {
                    label: 'No',
                    onClick: () => { }
                }
            ]
        });
    }

    const handleDisplaySession = (sessionId) => {
        const selectedSession = sessions.find(session => session.session_id === sessionId);
        chat_context.replaceSession(selectedSession);
        changeView("home");
    }

    const displayChats = (sessions) => {
        return sessions.map((session, index) => {
            const firstQuery = session.queries.length > 0 ? session.queries[0].user_content : "";
            return (
                <Row className={`history session`} key={index}>
                    <Col xs={{ span: 4 }} className={`history_date soft_text`}>{formatTimestamp(session.timestamp)}</Col>
                    <Col xs={{ span: 7 }} className={`history_query soft_text`} onClick={() => { handleDisplaySession(session.session_id) }}>{truncateString(firstQuery, 38)}</Col>
                    <Col xs={1} className={`soft_text trashit`} onClick={() => { handleDelete(session.session_id) }}><Trash color="#666" size={20} /></Col>
                </Row>
            );
        });
    }

    return (
        <Container className={`body archive`}>
            <div className={`box`}>
                <Row className={`history header`}>
                    <Col xs={{ span: 4 }} className={`history_date soft_text`}>Date</Col>
                    <Col xs={{ span: 7 }} className={`history_query soft_text`}>Starting Query</Col>
                    <Col xs={1} className={`soft_text trashit`} onClick={handleDeleteAll}><Trash color="red" size={20} /></Col>
                </Row>
                {displayChats(sessions)}
            </div>
        </Container>
    );
}

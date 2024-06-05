import React from "react";
import { Container } from 'react-bootstrap';
import { Messages } from "../../components/messages/messages";
 
export function Home(){
    return (
                <Container className={`body`}>
                    <Messages/>
                </Container>
            );
}
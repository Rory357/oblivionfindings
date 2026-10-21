import { createContext, useContext } from 'react';

/** Only one composer may own a conversation's in-memory draft and upload queue. */
export const CallConversationContext = createContext<{
    actorId: number;
    conversationId: number;
    mode?: 'call' | 'followup';
    returnToCall: () => void;
} | null>(null);

export const useCallConversation = () => useContext(CallConversationContext);

import { beforeEach, describe, expect, it } from 'vitest';
import { chatDraftKey, setChatDraftActor } from '../api';
import {
    addToChatOutbox,
    CHAT_OUTBOX_LIMIT,
    readChatOutbox,
    removeFromChatOutbox,
    updateChatOutbox,
} from './chat-outbox';

const message = { content: 'On my way', mentions: [], reply: null };

describe('chat outbox', () => {
    beforeEach(() => {
        setChatDraftActor(null);
    });

    it('keeps each waiting message with its own send reference', () => {
        const key = chatDraftKey(1, null, 71);
        const first = addToChatOutbox(key, message)!;
        const second = addToChatOutbox(key, { ...message, content: 'Late' })!;
        expect(first.id).not.toBe(second.id);
        expect(first.id).toMatch(/^[0-9a-f-]{36}$/);
        expect(readChatOutbox(key).map((entry) => entry.content)).toEqual([
            'On my way',
            'Late',
        ]);
        expect(readChatOutbox(key)[0].state).toBe('waiting');
    });

    it('updates and removes one message without touching the others', () => {
        const key = chatDraftKey(1, null, 71);
        const first = addToChatOutbox(key, message)!;
        const second = addToChatOutbox(key, message)!;
        updateChatOutbox(key, first.id, {
            state: 'uncertain',
            error: 'Not sure',
        });
        expect(readChatOutbox(key)[0]).toMatchObject({
            state: 'uncertain',
            error: 'Not sure',
        });
        removeFromChatOutbox(key, first.id);
        expect(readChatOutbox(key).map((entry) => entry.id)).toEqual([
            second.id,
        ]);
    });

    it('refuses more than the limit', () => {
        const key = chatDraftKey(1, null, 71);
        for (let index = 0; index < CHAT_OUTBOX_LIMIT; index++)
            expect(addToChatOutbox(key, message)).not.toBeNull();
        expect(addToChatOutbox(key, message)).toBeNull();
        expect(readChatOutbox(key)).toHaveLength(CHAT_OUTBOX_LIMIT);
    });

    it('is emptied when the signed-in person changes, and never keeps another person’s messages', () => {
        const key = chatDraftKey(1, null, 71);
        addToChatOutbox(key, message);
        chatDraftKey(2, null, 71);
        expect(readChatOutbox(key)).toEqual([]);
        expect(addToChatOutbox(key, message)).toBeNull();
    });
});

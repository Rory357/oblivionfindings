import { useRef } from 'react';
import { ChatError, conversationPath } from '../api';
import { chatActorRequest } from '../chat-actor-request';
import type { ChatConversation, ChatPolicy } from '../types';
import {
    readChatOutbox,
    removeFromChatOutbox,
    updateChatOutbox,
    useChatOutbox,
    type ChatOutboxEntry,
} from './chat-outbox';
import type { ThreadComposer } from './use-thread-composer';
import type { ThreadHistory } from './use-thread-history';

/**
 * Sends what waited in the outbox, one message at a time and only when the person asks. Each
 * message keeps its own send reference: a message whose result is unknown is checked before it is
 * posted again, so it can never arrive twice. The server still refuses a message whose audience
 * changed until the person has reviewed the new audience.
 */
export function useThreadOutbox({
    actorId,
    conversationId,
    conversation,
    policy,
    composer,
    history,
    onChange,
}: {
    actorId: number;
    conversationId: number;
    conversation: ChatConversation | undefined;
    policy: ChatPolicy;
    composer: ThreadComposer;
    history: ThreadHistory;
    onChange?: () => void;
}) {
    const key = composer.key;
    const entries = useChatOutbox(key);
    const flushing = useRef(false);
    const canSend =
        composer.online && !!conversation?.can_send && policy.enabled;

    async function sendOne(entry: ChatOutboxEntry): Promise<boolean> {
        const current = readChatOutbox(key).find(
            (item) => item.id === entry.id,
        );
        if (
            !current ||
            current.state === 'sending' ||
            !conversation ||
            !canSend
        )
            return false;
        if (conversation.audience_review_required) {
            composer.setAudienceReview(conversation);
            return false;
        }
        const id = conversationId;
        updateChatOutbox(key, current.id, { state: 'sending', error: '' });
        try {
            if (current.state === 'uncertain') {
                const outcome = await chatActorRequest<{
                    state: 'confirmed' | 'unconfirmed';
                    mutation_id: string;
                    can_retry: boolean;
                }>(
                    actorId,
                    `${conversationPath(id)}/send-outcomes/${encodeURIComponent(current.id)}`,
                    { signal: AbortSignal.timeout(15000) },
                );
                if (
                    outcome?.mutation_id === current.id &&
                    outcome.state === 'confirmed'
                ) {
                    removeFromChatOutbox(key, current.id);
                    if (history.active.current === id) {
                        await history.load(undefined, true);
                        onChange?.();
                    }
                    return true;
                }
                if (outcome?.state !== 'unconfirmed' || !outcome.can_retry)
                    throw new ChatError(
                        'No confirmed result yet. The message is kept here; try again in a moment.',
                        0,
                    );
            }
            const body = new FormData();
            body.set('mutation_id', current.id);
            body.set('content', current.content);
            if (conversation.audience_review_token)
                body.set('audience_token', conversation.audience_review_token);
            current.mentions.forEach((mention, index) => {
                body.set(
                    `mentions[${index}][user_id]`,
                    String(mention.user_id),
                );
                body.set(`mentions[${index}][start]`, String(mention.start));
                body.set(`mentions[${index}][length]`, String(mention.length));
            });
            if (current.reply)
                body.set('reply_to_id', String(current.reply.id));
            await chatActorRequest(
                actorId,
                `${conversationPath(id)}/messages`,
                {
                    method: 'POST',
                    body,
                    signal: AbortSignal.timeout(30000),
                },
            );
            removeFromChatOutbox(key, current.id);
            if (history.active.current === id) {
                await history.load(undefined, true);
                onChange?.();
            }
            return true;
        } catch (error) {
            if (
                error instanceof ChatError &&
                error.code === 'chat_audience_review_required'
            ) {
                // The server confirms this message was not posted: review the audience first.
                updateChatOutbox(key, current.id, {
                    state: 'waiting',
                    error: '',
                });
                const latest = await history.load(undefined, true);
                if (latest && history.active.current === id)
                    composer.setAudienceReview(latest);
                return false;
            }
            // A refusal (4xx) means it was not posted. Anything else may have arrived.
            const refused =
                error instanceof ChatError &&
                error.status >= 400 &&
                error.status < 500 &&
                error.status !== 408 &&
                error.status !== 429;
            updateChatOutbox(key, current.id, {
                state: refused ? 'failed' : 'uncertain',
                error: refused
                    ? (error as Error).message
                    : "Not sure it sent. Sending again checks first, so it won't arrive twice.",
            });
            return false;
        }
    }

    async function send(entry: ChatOutboxEntry) {
        if (flushing.current) return;
        flushing.current = true;
        try {
            await sendOne(entry);
        } finally {
            flushing.current = false;
        }
    }

    /** Sends the waiting messages in the order they were written; stops at the first that fails. */
    async function sendAll() {
        if (flushing.current) return;
        flushing.current = true;
        try {
            for (const entry of readChatOutbox(key)) {
                if (entry.state === 'failed') continue;
                if (!(await sendOne(entry))) break;
            }
        } finally {
            flushing.current = false;
        }
    }

    /** Moves a waiting message back into the composer to change it. */
    function edit(entry: ChatOutboxEntry) {
        if (!composer.empty) return;
        removeFromChatOutbox(key, entry.id);
        composer.loadIntoComposer(entry);
    }

    function discard(entry: ChatOutboxEntry) {
        removeFromChatOutbox(key, entry.id);
    }

    return { entries, canSend, send, sendAll, edit, discard };
}

export type ThreadOutbox = ReturnType<typeof useThreadOutbox>;

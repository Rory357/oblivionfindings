import { useCallback, useSyncExternalStore } from 'react';
import { chatMemoryAccepts, onChatActorReset } from '../api';
import type { ChatMention, ChatMessage } from '../types';

/**
 * The offline outbox (spec 6.1, plan C2.7): messages written while the browser is offline wait here,
 * each with its own send reference, until the person sends them after reconnecting. Nothing sends by
 * itself, so a message never lands silently in an audience that changed meanwhile. Memory only, like
 * drafts: care content is never written to browser storage, and the outbox is emptied when the
 * signed-in person changes.
 */
export type ChatOutboxEntry = {
    /** Also the send's mutation_id, so sending again can never post it twice. */
    id: string;
    content: string;
    mentions: ChatMention[];
    reply: ChatMessage | null;
    queuedAt: string;
    /** waiting: never sent. sending: in flight. uncertain: may have arrived; sending again checks first. failed: refused. */
    state: 'waiting' | 'sending' | 'uncertain' | 'failed';
    error: string;
};

export const CHAT_OUTBOX_LIMIT = 20;

const boxes = new Map<string, ChatOutboxEntry[]>();
const listeners = new Set<() => void>();
const EMPTY: ChatOutboxEntry[] = [];

function publish(key: string, entries: ChatOutboxEntry[]) {
    if (entries.length) boxes.set(key, entries);
    else boxes.delete(key);
    listeners.forEach((listener) => listener());
}

onChatActorReset(() => {
    boxes.clear();
    listeners.forEach((listener) => listener());
});

export function readChatOutbox(key: string): ChatOutboxEntry[] {
    return boxes.get(key) ?? EMPTY;
}

export function addToChatOutbox(
    key: string,
    message: Pick<ChatOutboxEntry, 'content' | 'mentions' | 'reply'>,
): ChatOutboxEntry | null {
    const current = readChatOutbox(key);
    if (!chatMemoryAccepts(key) || current.length >= CHAT_OUTBOX_LIMIT)
        return null;
    const entry: ChatOutboxEntry = {
        ...message,
        id: crypto.randomUUID(),
        queuedAt: new Date().toISOString(),
        state: 'waiting',
        error: '',
    };
    publish(key, [...current, entry]);
    return entry;
}

export function updateChatOutbox(
    key: string,
    id: string,
    update: Partial<Pick<ChatOutboxEntry, 'state' | 'error'>>,
) {
    const current = readChatOutbox(key);
    if (!current.some((entry) => entry.id === id)) return;
    publish(
        key,
        current.map((entry) =>
            entry.id === id ? { ...entry, ...update } : entry,
        ),
    );
}

export function removeFromChatOutbox(key: string, id: string) {
    const current = readChatOutbox(key);
    if (current.some((entry) => entry.id === id))
        publish(
            key,
            current.filter((entry) => entry.id !== id),
        );
}

export function clearChatOutbox(key: string) {
    if (boxes.has(key)) publish(key, EMPTY);
}

function subscribe(listener: () => void) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

export function useChatOutbox(key: string): ChatOutboxEntry[] {
    const read = useCallback(() => readChatOutbox(key), [key]);
    return useSyncExternalStore(subscribe, read, read);
}

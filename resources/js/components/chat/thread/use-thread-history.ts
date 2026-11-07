import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type RefObject,
} from 'react';
import { toast } from 'sonner';
import { ChatError, conversationPath, saveChatThreadMemory } from '../api';
import { chatActorRequest } from '../chat-actor-request';
import { applyChatChanges, readChatChanges } from '../chat-sync';
import type { ChatConversation, ChatHistory, ChatMessage } from '../types';

/** What the rest of the thread does when the history changes under it. */
export type ThreadHistoryEffects = {
    /** Live changes replaced these messages (possibly none). */
    messagesChanged: (updated: Map<number, ChatMessage>) => void;
    /** The authorised snapshot was reset: drop anything shown to the earlier audience. */
    snapshotReset: () => void;
    /** Access to the conversation ended. */
    accessLost: () => void;
    /** The history now shows the messages around one message. */
    jumped: () => void;
};

/**
 * The conversation's messages: the first load, live catch-up through the change cursor, older pages,
 * and the "around one message" context view. Owns the reader's position state (latest or context).
 */
export function useThreadHistory({
    actorId,
    conversationId,
    keyRef,
    effects,
    viewport,
}: {
    actorId: number;
    conversationId: number;
    keyRef: RefObject<string>;
    effects: RefObject<ThreadHistoryEffects>;
    viewport: RefObject<HTMLDivElement | null>;
}) {
    const [history, setHistory] = useState<ChatHistory | null>(null);
    const historyRef = useRef<ChatHistory | null>(null);
    historyRef.current = history;
    const historyAnchor = useRef<number | null>(null);
    const loadSequence = useRef(0);
    const invalidateLoads = useCallback(() => {
        ++loadSequence.current;
    }, []);
    const [failure, setFailure] = useState('');
    const [loading, setLoading] = useState(true);
    const [older, setOlder] = useState(false);
    const [atEnd, setAtEnd] = useState(true);
    const [contextMessage, setContextMessage] = useState<number | null>(null);
    const contextRef = useRef<number | null>(null);
    const restorePosition = useRef<{ id: number; offset: number } | null>(null);
    /** A message jumped to, to scroll into view and focus once it is rendered. */
    const pendingFocus = useRef<number | null>(null);
    useEffect(() => {
        const messageId = pendingFocus.current;
        if (messageId === null) return;
        if (contextRef.current !== messageId) {
            pendingFocus.current = null;
            return;
        }
        const target = viewport.current?.querySelector<HTMLElement>(
            `[data-chat-message="${messageId}"]`,
        );
        if (!target) return;
        pendingFocus.current = null;
        target.scrollIntoView({ block: 'center' });
        target.focus({ preventScroll: true });
    }, [history, viewport]);
    const active = useRef<number | null>(conversationId);
    useEffect(() => {
        active.current = conversationId;
        return () => {
            active.current = null;
        };
    }, [conversationId]);
    active.current = conversationId;

    const leaveContext = useCallback(() => {
        contextRef.current = null;
        setContextMessage(null);
        restorePosition.current = null;
        saveChatThreadMemory(keyRef.current, {
            readAnchor: null,
            readOffset: 0,
        });
    }, [keyRef]);

    const load = useCallback(
        async (
            signal?: AbortSignal,
            refreshContext = false,
        ): Promise<ChatConversation | undefined> => {
            const anchor = contextRef.current;
            // A context view still receives removals and access changes; it does not
            // append live messages or advance the user's read position.
            if (
                anchor !== null &&
                !refreshContext &&
                !historyRef.current?.sync_cursor
            )
                return;
            const id = conversationId;
            const sequence = ++loadSequence.current;
            const previous = historyRef.current;
            const stale = () =>
                active.current !== id ||
                signal?.aborted ||
                contextRef.current !== anchor ||
                sequence !== loadSequence.current;
            try {
                if (
                    previous?.sync_cursor &&
                    previous.conversation.id === id &&
                    historyAnchor.current === anchor
                ) {
                    try {
                        const changes = await readChatChanges(
                            actorId,
                            id,
                            previous.sync_cursor,
                            signal,
                        );
                        if (stale()) return;
                        setHistory((current) =>
                            current
                                ? applyChatChanges(
                                      current,
                                      changes,
                                      anchor !== null,
                                  )
                                : current,
                        );
                        effects.current.messagesChanged(
                            new Map(
                                changes.messages.map((message) => [
                                    message.id,
                                    message,
                                ]),
                            ),
                        );
                        setFailure('');
                        return changes.conversation;
                    } catch (error) {
                        if (
                            !(error instanceof ChatError) ||
                            error.code !== 'chat_sync_reset'
                        )
                            throw error;
                        if (stale()) return;
                        // Do not retain the previous audience's messages or search results
                        // while loading the replacement authorised snapshot.
                        setHistory(null);
                        historyRef.current = null;
                        effects.current.snapshotReset();
                    }
                }
                const next = await chatActorRequest<ChatHistory>(
                    actorId,
                    `${conversationPath(id)}/messages${anchor ? `?around=${anchor}` : ''}`,
                    { signal },
                );
                if (stale()) return;
                historyAnchor.current = anchor;
                setHistory((previous) => {
                    if (next.sync_cursor) return next;
                    if (anchor || !previous || previous.conversation.id !== id)
                        return next;
                    const earliest = next.messages[0]?.id ?? Infinity;
                    const retained = previous.messages.filter(
                        (message) => message.id < earliest,
                    );
                    return {
                        ...next,
                        has_more: retained.length
                            ? previous.has_more
                            : next.has_more,
                        messages: [...retained, ...next.messages],
                    };
                });
                setFailure('');
                return next.conversation;
            } catch (error) {
                if (
                    signal?.aborted ||
                    active.current !== id ||
                    contextRef.current !== anchor ||
                    sequence !== loadSequence.current
                )
                    return;
                if (
                    error instanceof ChatError &&
                    [401, 403, 404, 419].includes(error.status)
                ) {
                    setHistory(null);
                    historyRef.current = null;
                    effects.current.accessLost();
                    if (anchor !== null) leaveContext();
                }
                setFailure(
                    error instanceof Error
                        ? error.message
                        : 'Chat could not connect.',
                );
            } finally {
                if (
                    active.current === id &&
                    !signal?.aborted &&
                    sequence === loadSequence.current
                )
                    setLoading(false);
            }
        },
        [conversationId, actorId, effects, leaveContext],
    );

    /** A search result showed this conversation is no longer available. */
    const dropHistory = useCallback(
        (message: string) => {
            invalidateLoads();
            setHistory(null);
            historyRef.current = null;
            setFailure(message);
        },
        [invalidateLoads],
    );

    /** Clears everything shown for the previous conversation. */
    const reset = useCallback(() => {
        setHistory(null);
        historyRef.current = null;
        historyAnchor.current = null;
        setLoading(true);
        setFailure('');
        restorePosition.current = null;
        contextRef.current = null;
        setContextMessage(null);
        setAtEnd(true);
    }, []);

    /** Reopens the reader where they left off, around a remembered message. */
    const restoreReadAnchor = useCallback(
        (anchor: number, offset: number) => {
            restorePosition.current = { id: anchor, offset };
            contextRef.current = anchor;
            setContextMessage(anchor);
            setAtEnd(false);
            void load(undefined, true);
        },
        [load],
    );

    const jumpToMessage = useCallback(
        async (messageId: number) => {
            const id = conversationId;
            contextRef.current = messageId;
            setContextMessage(messageId);
            setAtEnd(false);
            try {
                const next = await chatActorRequest<ChatHistory>(
                    actorId,
                    `${conversationPath(id)}/messages?around=${messageId}`,
                );
                if (active.current !== id || contextRef.current !== messageId)
                    return;
                historyAnchor.current = messageId;
                setHistory(next);
                effects.current.jumped();
                saveChatThreadMemory(keyRef.current, {
                    readAnchor: messageId,
                    readOffset: 0,
                });
                // Focused once the rows are on screen (see the effect below), not on a timer.
                pendingFocus.current = messageId;
            } catch (error) {
                if (active.current === id && contextRef.current === messageId) {
                    contextRef.current = null;
                    setContextMessage(null);
                    toast.error((error as Error).message);
                }
            }
        },
        [actorId, conversationId, effects, keyRef],
    );

    /** Leaves the context view and reloads the latest messages. */
    const returnToLatest = useCallback(() => {
        saveChatThreadMemory(keyRef.current, {
            readAnchor: null,
            readOffset: 0,
        });
        restorePosition.current = null;
        contextRef.current = null;
        setContextMessage(null);
        setAtEnd(true);
        setHistory(null);
        setLoading(true);
        void load(undefined, true);
    }, [keyRef, load]);

    /** After a send: show the latest messages again. */
    const followLatest = useCallback(() => {
        setAtEnd(true);
        contextRef.current = null;
        setContextMessage(null);
    }, []);

    /**
     * Loads the page before the first loaded message. The list keeps the reader's place
     * (it re-pins the row they were looking at), so nothing here moves the scroll position.
     * Resolves true when a page was added, false when it failed, null when skipped.
     */
    const loadOlder = useCallback(async (): Promise<boolean | null> => {
        const current = historyRef.current;
        if (!current?.messages[0] || older) return null;
        const id = conversationId;
        const anchor = contextRef.current;
        const cursor = current.sync_cursor;
        setOlder(true);
        try {
            const next = await chatActorRequest<ChatHistory>(
                actorId,
                `${conversationPath(id)}/messages?before=${current.messages[0].id}`,
            );
            if (
                active.current !== id ||
                contextRef.current !== anchor ||
                historyRef.current?.sync_cursor !== cursor
            )
                return null;
            setHistory((latest) =>
                latest && latest.sync_cursor === cursor
                    ? {
                          ...latest,
                          has_more: next.has_more,
                          messages: [
                              ...next.messages,
                              ...latest.messages.filter(
                                  (message) =>
                                      !next.messages.some(
                                          (m) => m.id === message.id,
                                      ),
                              ),
                          ],
                      }
                    : latest,
            );
            return true;
        } catch (error) {
            toast.error((error as Error).message);
            return false;
        } finally {
            setOlder(false);
        }
    }, [actorId, conversationId, older]);

    return {
        history,
        setHistory,
        historyRef,
        failure,
        loading,
        older,
        atEnd,
        setAtEnd,
        contextMessage,
        contextRef,
        restorePosition,
        active,
        load,
        invalidateLoads,
        dropHistory,
        reset,
        restoreReadAnchor,
        jumpToMessage,
        returnToLatest,
        followLatest,
        loadOlder,
    };
}

export type ThreadHistory = ReturnType<typeof useThreadHistory>;

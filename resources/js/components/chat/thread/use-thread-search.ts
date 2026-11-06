import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type RefObject,
} from 'react';
import { ChatError, conversationPath, saveChatThreadMemory } from '../api';
import { chatActorRequest } from '../chat-actor-request';
import { watchChatUpdates } from '../chat-update-watch';
import type { ChatHistory, ChatMessage } from '../types';

/**
 * Search within the open conversation. Results follow live changes to the conversation; any
 * change of audience (`authority`) discards results that were fetched for the earlier one.
 */
export function useThreadSearch({
    actorId,
    conversationId,
    keyRef,
    hasConversation,
    invalidateLoads,
    dropHistory,
}: {
    actorId: number;
    conversationId: number;
    keyRef: RefObject<string>;
    hasConversation: boolean;
    invalidateLoads: () => void;
    dropHistory: (message: string) => void;
}) {
    const [query, setQueryState] = useState('');
    const [open, setOpenState] = useState(false);
    const [results, setResults] = useState<ChatMessage[] | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [retryCount, setRetryCount] = useState(0);
    const authority = useRef(0);

    const setQuery = useCallback(
        (value: string) => {
            setQueryState(value);
            saveChatThreadMemory(keyRef.current, { query: value });
        },
        [keyRef],
    );
    const setOpen = useCallback(
        (value: boolean) => {
            setOpenState(value);
            saveChatThreadMemory(keyRef.current, { searchOpen: value });
        },
        [keyRef],
    );
    const retry = useCallback(() => setRetryCount((value) => value + 1), []);

    /** Results fetched so far no longer belong to the conversation's current audience. */
    const revoke = useCallback(() => {
        ++authority.current;
        setResults(null);
    }, []);

    /** Live changes replaced these messages: refresh the results that show them. */
    const messagesChanged = useCallback((updated: Map<number, ChatMessage>) => {
        if (updated.size) {
            ++authority.current;
            window.dispatchEvent(new Event('oblivion:chat-search-refresh'));
        }
        setResults(
            (current) =>
                current
                    ?.map((message) => updated.get(message.id) ?? message)
                    .filter((message) => !message.deleted) ?? null,
        );
    }, []);

    /** Closes search without remembering it (the history moved to one message, or the conversation changed). */
    const dismiss = useCallback(() => {
        setResults(null);
        setOpenState(false);
        setQueryState('');
    }, []);

    /** Reopens search as the person left it in this conversation. */
    const restore = useCallback(
        (remembered: { query: string; searchOpen: boolean }) => {
            setQueryState(remembered.query);
            setOpenState(remembered.searchOpen);
        },
        [],
    );

    useEffect(() => {
        setError('');
        setBusy(false);
        if (!hasConversation || !open || query.trim().length < 2) {
            setResults(null);
            return;
        }
        setResults([]);
        setBusy(true);
        const controller = new AbortController();
        let stopUpdates: (() => void) | undefined;
        const timer = window.setTimeout(() => {
            stopUpdates = watchChatUpdates(
                async () => {
                    const current = authority.current;
                    try {
                        const result = await chatActorRequest<ChatHistory>(
                            actorId,
                            `${conversationPath(conversationId)}/messages?q=${encodeURIComponent(query.trim())}`,
                            { signal: controller.signal },
                        );
                        if (
                            controller.signal.aborted ||
                            current !== authority.current
                        )
                            return false;
                        setResults(result.messages);
                        setError('');
                        return true;
                    } catch (failure) {
                        if (
                            controller.signal.aborted ||
                            current !== authority.current
                        )
                            return false;
                        if (
                            failure instanceof ChatError &&
                            [401, 403, 404, 419].includes(failure.status)
                        ) {
                            ++authority.current;
                            setResults(null);
                            dropHistory(failure.message);
                        } else {
                            setError((failure as Error).message);
                        }
                        return false;
                    } finally {
                        if (
                            !controller.signal.aborted &&
                            current === authority.current
                        )
                            setBusy(false);
                    }
                },
                {
                    // Search results follow changes to this conversation only.
                    topics: ['conversation.changed'],
                    match: (event) =>
                        event.type === 'conversation.changed' &&
                        event.conversation_id === conversationId,
                    events: [
                        'oblivion:chat-refresh',
                        'oblivion:chat-search-refresh',
                    ],
                },
            );
        }, 250);
        return () => {
            controller.abort();
            stopUpdates?.();
            clearTimeout(timer);
        };
    }, [
        query,
        open,
        conversationId,
        actorId,
        hasConversation,
        retryCount,
        invalidateLoads,
        dropHistory,
    ]);

    return {
        query,
        setQuery,
        open,
        setOpen,
        results,
        error,
        busy,
        retry,
        revoke,
        messagesChanged,
        dismiss,
        restore,
    };
}

export type ThreadSearch = ReturnType<typeof useThreadSearch>;

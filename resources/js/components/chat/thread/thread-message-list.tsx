import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/datetime';
import { Loader2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';
import type { ChatPreferences } from '../chat-personal-preferences';
import type { ChatMessage } from '../types';
import { useMessageWindow } from './message-window';
import { ThreadMessage, type ThreadMessageProps } from './thread-message';

const canObserve = () => typeof IntersectionObserver !== 'undefined';

/**
 * The message history. Older messages load as the reader nears the top (spec 6.2: without moving
 * the reader's position: the row they were looking at is pinned in place); a button remains where
 * the browser can't watch the scroll, and after a failed load. Long histories are windowed by
 * useMessageWindow.
 */
export function ThreadMessageList({
    viewport,
    messages,
    hasMore,
    inSearch,
    searchPending,
    contextMessage,
    firstUnreadId,
    personal,
    actorId,
    conversationId,
    older,
    loadOlder,
    onScroll,
    onReturnToLatest,
    onJump,
    onReact,
    onActions,
    children,
}: {
    viewport: RefObject<HTMLDivElement | null>;
    messages: ChatMessage[];
    hasMore: boolean;
    inSearch: boolean;
    /** Search is still running or paused, so "no matches" would be premature. */
    searchPending: boolean;
    contextMessage: number | null;
    firstUnreadId: number | null | undefined;
    personal: Pick<ChatPreferences, 'spacing' | 'text_size'>;
    actorId: number;
    conversationId: number;
    older: boolean;
    loadOlder: () => Promise<boolean | null>;
    onScroll: () => void;
    onReturnToLatest: () => void;
    onJump: ThreadMessageProps['onJump'];
    onReact: ThreadMessageProps['onReact'];
    onActions: ThreadMessageProps['onActions'];
    /** Messages not in the history yet (an unconfirmed send, the outbox). */
    children?: ReactNode;
}) {
    const rows = useRef<HTMLDivElement>(null);
    const ids = useMemo(
        () => messages.map((message) => message.id),
        [messages],
    );
    const rowWindow = useMessageWindow(viewport, rows, ids);
    const sentinel = useRef<HTMLDivElement>(null);
    const [olderFailed, setOlderFailed] = useState(false);
    const pinned = useRef<{ id: number; offset: number } | null>(null);
    const canLoadOlder = hasMore && !inSearch;
    const firstId = ids[0];

    const requestOlder = useCallback(async () => {
        const element = viewport.current;
        if (element) {
            const top = element.getBoundingClientRect().top;
            const row = Array.from(
                element.querySelectorAll<HTMLElement>('[data-chat-message]'),
            ).find((item) => item.getBoundingClientRect().bottom > top);
            pinned.current = row
                ? {
                      id: Number(row.dataset.chatMessage),
                      offset: row.getBoundingClientRect().top - top,
                  }
                : null;
        }
        const result = await loadOlder();
        if (result !== null) setOlderFailed(!result);
    }, [viewport, loadOlder]);
    const requestRef = useRef(requestOlder);
    requestRef.current = requestOlder;

    // Re-observed whenever a page arrives, so a page that still doesn't fill the view loads the next.
    useEffect(() => {
        const target = sentinel.current;
        if (!canLoadOlder || olderFailed || older || !canObserve() || !target)
            return;
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting))
                    void requestRef.current();
            },
            { root: viewport.current, rootMargin: '600px 0px 0px 0px' },
        );
        observer.observe(target);
        return () => observer.disconnect();
    }, [canLoadOlder, olderFailed, older, firstId, viewport]);

    // Keep the pinned row where it was once the older page is in place.
    useLayoutEffect(() => {
        const saved = pinned.current;
        const element = viewport.current;
        if (!saved || !element || firstId === saved.id) return;
        pinned.current = null;
        const row = element.querySelector<HTMLElement>(
            `[data-chat-message="${saved.id}"]`,
        );
        if (!row) return;
        const offset =
            row.getBoundingClientRect().top -
            element.getBoundingClientRect().top;
        if (Math.abs(offset - saved.offset) > 0.5)
            element.scrollTop += offset - saved.offset;
    }, [firstId, viewport]);

    return (
        <div
            ref={viewport}
            role="log"
            aria-label="Messages"
            aria-live="off"
            aria-busy={older || undefined}
            className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 sm:px-6"
            onScroll={() => {
                rowWindow.update();
                onScroll();
            }}
        >
            {canLoadOlder && (
                <div ref={sentinel} className="mb-4 text-center">
                    {older ? (
                        <p
                            role="status"
                            className="flex items-center justify-center gap-2 text-sm text-muted-foreground"
                        >
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            Loading earlier messages…
                        </p>
                    ) : (
                        (olderFailed || !canObserve()) && (
                            <Button
                                variant="outline"
                                onClick={() => void requestOlder()}
                            >
                                {olderFailed
                                    ? 'Try loading earlier messages again'
                                    : 'Load earlier messages'}
                            </Button>
                        )
                    )}
                </div>
            )}
            {!messages.length && !searchPending && (
                <div className="mx-auto max-w-sm py-14 text-center">
                    <h3 className="font-semibold">
                        {inSearch
                            ? 'No matching messages'
                            : 'Start the conversation'}
                    </h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {inSearch
                            ? 'Try a different word or phrase.'
                            : 'Send a message below. Files stay in this conversation until separately published with permission.'}
                    </p>
                </div>
            )}
            {contextMessage && (
                <Button
                    variant="outline"
                    className="mb-3 min-h-[44px]"
                    onClick={onReturnToLatest}
                >
                    Return to latest messages
                </Button>
            )}
            <div ref={rows}>
                {rowWindow.before > 0 && (
                    <div
                        aria-hidden="true"
                        style={{ height: rowWindow.before }}
                    />
                )}
                {messages
                    .slice(rowWindow.start, rowWindow.end + 1)
                    .map((message, offset) => {
                        const index = rowWindow.start + offset;
                        return (
                            <ThreadMessage
                                key={message.id}
                                message={message}
                                actorId={actorId}
                                conversationId={conversationId}
                                showDay={
                                    index === 0 ||
                                    formatDate(
                                        messages[index - 1].created_at,
                                    ) !== formatDate(message.created_at)
                                }
                                firstUnread={message.id === firstUnreadId}
                                highlighted={contextMessage === message.id}
                                inSearch={inSearch}
                                spacing={personal.spacing}
                                textSize={personal.text_size}
                                onJump={onJump}
                                onReact={onReact}
                                onActions={onActions}
                            />
                        );
                    })}
                {rowWindow.after > 0 && (
                    <div
                        aria-hidden="true"
                        style={{ height: rowWindow.after }}
                    />
                )}
            </div>
            {children}
        </div>
    );
}

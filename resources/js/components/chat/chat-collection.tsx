import { audioTime } from '@/components/files/audio-preview';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { LoadingState } from '@/components/ui/loading-state';
import { formatDateTime } from '@/lib/datetime';
import { Bookmark, Files, Loader2, Pin } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { isChatAccessDenied } from './api';
import {
    collectionItems,
    readCollectionPage,
    refreshCollection,
    type ChatCollectionItem,
    type ChatCollectionPage,
} from './chat-collection-sync';
import { useChatRealtime } from './chat-realtime';
import { watchChatUpdates } from './chat-update-watch';
import type { ChatMessage } from './types';

export type { ChatCollectionItem } from './chat-collection-sync';
const refreshEvent = 'oblivion:chat-collection-refresh';

function mediaLabel(message: ChatMessage) {
    return (
        [
            ...message.attachments.map((file) => {
                if (file.media_kind !== 'voice_note') return file.name;
                const duration = file.duration_ms;
                return duration && Number.isFinite(duration) && duration > 0
                    ? `Voice note · ${audioTime(duration / 1000)}`
                    : 'Voice note';
            }),
            ...message.legacy_attachments.map((file) =>
                file.url ? file.name : `${file.name} (unavailable)`,
            ),
        ].join(', ') || 'Attachment unavailable'
    );
}

type ChatCollectionProps = {
    actorId: number;
    kind: 'saved' | 'pinned' | 'media';
    conversationId?: number;
    clientId?: number | null;
    onSelect: (item: ChatCollectionItem) => void;
};
export function ChatCollection(props: ChatCollectionProps) {
    const [open, setOpen] = useState(false);
    return (
        <CollectionSession
            key={`${props.actorId}:${props.clientId}:${props.conversationId}:${props.kind}`}
            {...props}
            open={open}
            setOpen={setOpen}
        />
    );
}
function CollectionSession({
    actorId,
    kind,
    conversationId,
    clientId,
    onSelect,
    open,
    setOpen,
}: ChatCollectionProps & { open: boolean; setOpen: (open: boolean) => void }) {
    const [page, setPage] = useState<ChatCollectionPage | null>(null);
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<{
        source: 'refresh' | 'more';
        message: string;
    } | null>(null);
    const request = useRef<AbortController | null>(null);
    const snapshot = useRef<ChatCollectionPage | null>(null);
    const busy = useRef(false);
    const queued = useRef(false);
    const dialog = useRef<HTMLDivElement>(null);
    const scrollArea = useRef<HTMLDivElement>(null);
    const position = useRef<{
        items: { id: string; offset: number }[];
        focused: string | null;
    } | null>(null);
    const realtime = useChatRealtime();
    const realtimeRef = useRef(realtime);
    useEffect(() => {
        realtimeRef.current = realtime;
    }, [realtime]);

    function replace(next: ChatCollectionPage | null) {
        const container = scrollArea.current;
        if (container) {
            const top = container.getBoundingClientRect().top;
            const rows = Array.from(
                container.querySelectorAll<HTMLElement>(
                    '[data-collection-message]',
                ),
            );
            const visible = rows.findIndex(
                (row) => row.getBoundingClientRect().bottom > top,
            );
            position.current = {
                items: rows.slice(Math.max(0, visible)).map((row) => ({
                    id: row.dataset.collectionMessage!,
                    offset: row.getBoundingClientRect().top - top,
                })),
                focused:
                    rows.find((row) => row.contains(document.activeElement))
                        ?.dataset.collectionMessage ?? null,
            };
        }
        snapshot.current = next;
        setPage(next);
    }

    useLayoutEffect(() => {
        const saved = position.current;
        const container = scrollArea.current;
        position.current = null;
        if (!saved || !container) return;
        const rows = Array.from(
            container.querySelectorAll<HTMLElement>(
                '[data-collection-message]',
            ),
        );
        const anchor = saved.items.find((item) =>
            rows.some((row) => row.dataset.collectionMessage === item.id),
        );
        const row = rows.find(
            (item) => item.dataset.collectionMessage === anchor?.id,
        );
        if (row && anchor)
            container.scrollTop +=
                row.getBoundingClientRect().top -
                container.getBoundingClientRect().top -
                anchor.offset;
        if (
            saved.focused &&
            !rows.some(
                (item) => item.dataset.collectionMessage === saved.focused,
            )
        )
            (
                row?.querySelector('button') ??
                dialog.current?.querySelector<HTMLElement>('h2')
            )?.focus({ preventScroll: true });
    }, [page]);
    const title =
        kind === 'saved'
            ? 'Saved for me'
            : kind === 'pinned'
              ? 'Pinned messages'
              : 'Shared media';
    const Icon = kind === 'saved' ? Bookmark : kind === 'pinned' ? Pin : Files;
    const params = new URLSearchParams({ kind });
    if (conversationId) params.set('conversation', String(conversationId));
    if (clientId) params.set('client', String(clientId));
    const path = `/chat/api/collections?${params}`;

    useEffect(() => {
        setPage(null);
        snapshot.current = null;
        position.current = null;
        busy.current = false;
        queued.current = false;
        setRefreshing(false);
        setError(null);
        if (!open) return;
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        const stop = watchChatUpdates(
            async () => {
                if (busy.current) {
                    queued.current = true;
                    return true;
                }
                busy.current = true;
                setRefreshing(true);
                try {
                    const result = await refreshCollection(
                        path,
                        snapshot.current,
                        controller.signal,
                        actorId,
                    );
                    if (controller.signal.aborted) return false;
                    replace(result);
                    setError((current) =>
                        current?.source === 'more' ? current : null,
                    );
                    return true;
                } catch (failure) {
                    if (controller.signal.aborted) return false;
                    if (isChatAccessDenied(failure)) replace(null);
                    setError({
                        source: 'refresh',
                        message: (failure as Error).message,
                    });
                    return false;
                } finally {
                    if (!controller.signal.aborted) {
                        busy.current = false;
                        setLoading(false);
                        setRefreshing(false);
                    }
                }
            },
            {
                interval: () =>
                    realtimeRef.current === 'live' ? 30000 : 10000,
                events: ['oblivion:chat-refresh', refreshEvent],
            },
        );
        return () => {
            stop();
            controller.abort();
        };
    }, [actorId, open, path]);

    async function more() {
        const previous = snapshot.current;
        const controller = request.current;
        if (
            busy.current ||
            !previous?.next_before ||
            !controller ||
            controller.signal.aborted
        )
            return;
        busy.current = true;
        setLoading(true);
        setError(null);
        try {
            const result = await readCollectionPage(
                `${path}&before=${previous.next_before}`,
                controller.signal,
                { 'X-Chat-Actor': String(actorId) },
            );
            if (!controller.signal.aborted)
                replace({
                    ...result,
                    scan_floor:
                        result.scan_floor ??
                        result.next_before ??
                        result.items.at(-1)?.message.id ??
                        previous.scan_floor,
                    items: collectionItems([
                        ...previous.items,
                        ...result.items,
                    ]),
                });
        } catch (failure) {
            if (!controller.signal.aborted) {
                if (isChatAccessDenied(failure)) replace(null);
                setError({
                    source: snapshot.current ? 'more' : 'refresh',
                    message: (failure as Error).message,
                });
            }
        } finally {
            if (!controller.signal.aborted) {
                busy.current = false;
                setLoading(false);
                if (queued.current) {
                    queued.current = false;
                    window.dispatchEvent(new Event(refreshEvent));
                }
            }
        }
    }

    useEffect(() => () => request.current?.abort(), []);
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                request.current?.abort();
                setOpen(value);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    className="frontline-tap justify-start"
                >
                    <Icon className="size-4" />
                    {kind === 'saved' ? 'Saved messages' : title}
                </Button>
            </DialogTrigger>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                ref={dialog}
                className="flex max-h-[85dvh] flex-col overflow-hidden [&>[data-slot=dialog-close]]:top-[16px] [&>[data-slot=dialog-close]]:right-[16px]"
            >
                <DialogHeader className="shrink-0 pr-6">
                    <DialogTitle tabIndex={-1}>{title}</DialogTitle>
                    <DialogDescription>
                        {kind === 'saved'
                            ? 'Only you can see what you save. Conversation access still applies.'
                            : 'Available to authorised conversation members.'}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <div role="alert" className="space-y-2 text-sm">
                        <p>{error.message}</p>
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            onClick={() =>
                                error.source === 'more'
                                    ? void more()
                                    : window.dispatchEvent(
                                          new Event(refreshEvent),
                                      )
                            }
                        >
                            Try again
                        </Button>
                    </div>
                )}
                <div
                    ref={scrollArea}
                    className="min-h-0 space-y-4 overflow-y-auto overscroll-contain p-1"
                >
                    {page?.items.length ? (
                        <ul className="space-y-2">
                            {page.items.map((item) => (
                                <li
                                    key={item.message.id}
                                    data-collection-message={item.message.id}
                                >
                                    <Button
                                        variant="ghost"
                                        className="frontline-tap h-auto w-full flex-col items-stretch gap-0 rounded-xl border bg-muted/20 p-3 text-left whitespace-normal focus-visible:outline-2 focus-visible:outline-primary"
                                        onClick={() => {
                                            request.current?.abort();
                                            setOpen(false);
                                            onSelect(item);
                                        }}
                                    >
                                        <span className="line-clamp-3 block text-sm font-medium break-words">
                                            {kind === 'media'
                                                ? mediaLabel(item.message)
                                                : item.message.content ||
                                                  'Attachment'}
                                        </span>
                                        <span className="mt-2 block text-xs text-muted-foreground">
                                            {item.title} ·{' '}
                                            {item.message.sender_name}
                                        </span>
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {formatDateTime(
                                                item.message.created_at,
                                            )}{' '}
                                            · View in conversation
                                        </span>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        page &&
                        !loading &&
                        !error && (
                            <p className="rounded-xl bg-muted/30 p-4 text-sm text-muted-foreground">
                                {page.next_before
                                    ? 'No available items in this part of the list. Continue to older items.'
                                    : kind === 'media'
                                      ? 'Photos, files and voice notes shared in this conversation will appear here.'
                                      : 'No items here yet. Use a message’s actions to add one.'}
                            </p>
                        )
                    )}
                    {loading && !page && (
                        <div role="status">
                            <LoadingState
                                message="Loading messages…"
                                className="py-8"
                            />
                        </div>
                    )}
                    {loading && page && (
                        <p
                            role="status"
                            className="flex items-center gap-2 text-sm"
                        >
                            <Loader2 className="size-4 animate-spin" />
                            Loading messages…
                        </p>
                    )}
                    {page?.next_before && !error && (
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            disabled={loading || refreshing}
                            onClick={() => void more()}
                        >
                            Load older items
                        </Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

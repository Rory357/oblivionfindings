import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/datetime';
import { ArrowLeft, Loader2, Search } from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { isChatAccessDenied } from './api';
import { readCollectionPage } from './chat-collection-sync';
import { useChatRealtime } from './chat-realtime';
import {
    refreshChatSearch,
    searchItems,
    type ChatSearchItem,
    type ChatSearchPage,
} from './chat-search-sync';
import { watchChatUpdates } from './chat-update-watch';

type ChatSearchProps = {
    actorId: number;
    clientId: number | null;
    active: boolean;
    onBack: () => void;
    onSelect: (item: ChatSearchItem) => void;
};
export function ChatSearch(props: ChatSearchProps) {
    return (
        <SearchSession key={`${props.actorId}:${props.clientId}`} {...props} />
    );
}
// Radix Select items cannot hold an empty value; the "All …" filters ('') map to this.
const ALL = '__all';

function SearchSession({
    actorId,
    clientId,
    active,
    onBack,
    onSelect,
}: ChatSearchProps) {
    const fieldId = useId();
    const [query, setQuery] = useState('');
    const [kind, setKind] = useState('messages');
    const [sender, setSender] = useState('');
    const [date, setDate] = useState('any');
    const [conversation, setConversation] = useState('');
    const [page, setPage] = useState<ChatSearchPage | null>(null);
    const [options, setOptions] = useState<ChatSearchPage['options']>({
        people: [],
        conversations: [],
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<{
        source: 'refresh' | 'more';
        message: string;
    } | null>(null);
    const [retry, setRetry] = useState(0);
    const snapshot = useRef<ChatSearchPage | null>(null);
    const request = useRef<AbortController | null>(null);
    const running = useRef(false);
    const queued = useRef(false);
    const lastPath = useRef('');
    const container = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const position = useRef<{
        rows: { id: string; offset: number }[];
        focused: string | null;
    } | null>(null);
    const returnTo = useRef<{ id: number; top: number } | null>(null);
    const restore = useRef(false);
    const realtime = useChatRealtime();
    const realtimeRef = useRef(realtime);
    useEffect(() => {
        realtimeRef.current = realtime;
    }, [realtime]);
    const textResults = kind === 'messages' || kind === 'files';
    const params = new URLSearchParams({ kind, q: query });
    if (clientId) params.set('client', String(clientId));
    if (conversation) params.set('conversation', conversation);
    if (textResults) {
        if (sender) params.set('sender', sender);
        params.set('date', date);
    }
    const path = `/chat/api/search?${params}`;
    const currentPath = useRef(path);
    currentPath.current = path;

    function replace(next: ChatSearchPage | null) {
        const area = container.current;
        if (area) {
            const top = area.getBoundingClientRect().top;
            const rows = Array.from(
                area.querySelectorAll<HTMLElement>('[data-search-result]'),
            );
            position.current = {
                rows: rows
                    .filter((row) => row.getBoundingClientRect().bottom > top)
                    .map((row) => ({
                        id: row.dataset.searchResult!,
                        offset: row.getBoundingClientRect().top - top,
                    })),
                focused:
                    rows.find((row) => row.contains(document.activeElement))
                        ?.dataset.searchResult ?? null,
            };
        }
        snapshot.current = next;
        setPage(next);
    }

    useEffect(() => {
        if (active) input.current?.focus();
    }, [active]);

    useEffect(() => {
        const controller = new AbortController();
        request.current = controller;
        running.current = false;
        queued.current = false;
        setError(null);
        if (lastPath.current !== path) {
            setPage(null);
            snapshot.current = null;
            returnTo.current = null;
        }
        lastPath.current = path;
        if (!active) {
            setPage(null);
            return () => controller.abort();
        }
        restore.current = !!returnTo.current;
        setBusy(true);
        let stop: (() => void) | undefined;
        const timer = setTimeout(() => {
            stop = watchChatUpdates(
                async () => {
                    if (running.current) {
                        queued.current = true;
                        return true;
                    }
                    running.current = true;
                    setBusy(true);
                    try {
                        const result = await refreshChatSearch(
                            path,
                            snapshot.current,
                            controller.signal,
                            actorId,
                        );
                        if (
                            controller.signal.aborted ||
                            currentPath.current !== path
                        )
                            return false;
                        replace(result);
                        setOptions(result.options);
                        setError((current) =>
                            current?.source === 'more' ? current : null,
                        );
                        return true;
                    } catch (failure) {
                        if (
                            controller.signal.aborted ||
                            currentPath.current !== path
                        )
                            return false;
                        if (isChatAccessDenied(failure)) {
                            replace(null);
                            setOptions({ people: [], conversations: [] });
                            returnTo.current = null;
                        }
                        setError({
                            source: 'refresh',
                            message: (failure as Error).message,
                        });
                        return false;
                    } finally {
                        if (!controller.signal.aborted) {
                            running.current = false;
                            setBusy(false);
                        }
                    }
                },
                {
                    interval: () =>
                        realtimeRef.current === 'live' ? 30000 : 10000,
                    events: [
                        'oblivion:chat-refresh',
                        'oblivion:chat-search-refresh',
                    ],
                },
            );
        }, 250);
        return () => {
            clearTimeout(timer);
            stop?.();
            controller.abort();
        };
    }, [actorId, clientId, path, active, retry]);

    useLayoutEffect(() => {
        const area = container.current;
        const saved = position.current;
        position.current = null;
        if (!area) return;
        if (page && restore.current) {
            restore.current = false;
            area.scrollTop = returnTo.current?.top ?? 0;
            (
                area.querySelector<HTMLElement>(
                    `[data-search-result="${returnTo.current?.id}"]`,
                ) ?? heading.current
            )?.focus({ preventScroll: true });
            return;
        }
        if (!saved) return;
        const rows = Array.from(
            area.querySelectorAll<HTMLElement>('[data-search-result]'),
        );
        const anchor = saved.rows.find((item) =>
            rows.some((row) => row.dataset.searchResult === item.id),
        );
        const row = rows.find(
            (item) => item.dataset.searchResult === anchor?.id,
        );
        if (row && anchor)
            area.scrollTop +=
                row.getBoundingClientRect().top -
                area.getBoundingClientRect().top -
                anchor.offset;
        if (
            saved.focused &&
            !rows.some((item) => item.dataset.searchResult === saved.focused)
        )
            (row ?? heading.current)?.focus({ preventScroll: true });
    }, [page]);

    async function more() {
        const previous = snapshot.current;
        const controller = request.current;
        if (
            !previous?.next_before ||
            !controller ||
            controller.signal.aborted ||
            running.current
        )
            return;
        running.current = true;
        setBusy(true);
        try {
            const result = await readCollectionPage<ChatSearchPage>(
                `${path}&before=${previous.next_before}`,
                controller.signal,
                { 'X-Chat-Actor': String(actorId) },
            );
            if (controller.signal.aborted || currentPath.current !== path)
                return;
            if (
                result.next_before !== null &&
                result.next_before >= previous.next_before
            )
                throw new Error(
                    'Search changed while loading. Please try again.',
                );
            const next = {
                ...result,
                scan_floor: result.scan_floor ?? previous.scan_floor,
                items: searchItems([...previous.items, ...result.items]),
            };
            replace(next);
            setOptions(next.options);
            setError(null);
        } catch (failure) {
            if (controller.signal.aborted || currentPath.current !== path)
                return;
            if (isChatAccessDenied(failure)) {
                replace(null);
                setOptions({ people: [], conversations: [] });
            }
            setError({ source: 'more', message: (failure as Error).message });
        } finally {
            if (!controller.signal.aborted) {
                running.current = false;
                setBusy(false);
                if (queued.current) {
                    queued.current = false;
                    window.dispatchEvent(
                        new Event('oblivion:chat-search-refresh'),
                    );
                }
            }
        }
    }
    const selectClass = 'mt-1 min-h-[44px] w-full min-w-0 bg-background';
    return (
        <section
            hidden={!active}
            className="min-h-0 min-w-0 flex-1"
            aria-label="Search Chat"
        >
            <div ref={container} className="h-full overflow-y-auto p-4 md:p-6">
                <div className="mb-5 flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-[44px] shrink-0"
                        aria-label="Back to conversations"
                        onClick={onBack}
                    >
                        <ArrowLeft className="size-5" />
                    </Button>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight"
                    >
                        Search Chat
                    </h2>
                </div>
                <div className="mb-5 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr]">
                    <label className="min-w-0 text-sm font-medium">
                        Search
                        <Input
                            ref={input}
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            maxLength={200}
                            placeholder="Words, people or files"
                            className="mt-1 min-h-[44px]"
                        />
                    </label>
                    <div className="min-w-0 text-sm font-medium">
                        <label htmlFor={`${fieldId}-kind`}>Find</label>
                        <Select value={kind} onValueChange={setKind}>
                            <SelectTrigger
                                id={`${fieldId}-kind`}
                                className={selectClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[
                                    'messages',
                                    'files',
                                    'people',
                                    'conversations',
                                ].map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {value[0].toUpperCase() +
                                            value.slice(1)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-0 text-sm font-medium">
                        <label htmlFor={`${fieldId}-sender`}>From</label>
                        <Select
                            disabled={!textResults}
                            value={sender || ALL}
                            onValueChange={(next) =>
                                setSender(next === ALL ? '' : next)
                            }
                        >
                            <SelectTrigger
                                id={`${fieldId}-sender`}
                                className={selectClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All people</SelectItem>
                                {sender &&
                                    !options.people.some(
                                        (person) =>
                                            String(person.id) === sender,
                                    ) && (
                                        <SelectItem value={sender} disabled>
                                            Selected person unavailable
                                        </SelectItem>
                                    )}
                                {options.people.map((person) => (
                                    <SelectItem
                                        key={person.id}
                                        value={String(person.id)}
                                    >
                                        {person.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-0 text-sm font-medium">
                        <label htmlFor={`${fieldId}-date`}>Date</label>
                        <Select
                            disabled={!textResults}
                            value={date}
                            onValueChange={setDate}
                        >
                            <SelectTrigger
                                id={`${fieldId}-date`}
                                className={selectClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="any">Any date</SelectItem>
                                <SelectItem value="today">Today</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-0 text-sm font-medium">
                        <label htmlFor={`${fieldId}-conversation`}>
                            Conversation
                        </label>
                        <Select
                            value={conversation || ALL}
                            onValueChange={(next) =>
                                setConversation(next === ALL ? '' : next)
                            }
                        >
                            <SelectTrigger
                                id={`${fieldId}-conversation`}
                                className={selectClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All conversations
                                </SelectItem>
                                {conversation &&
                                    !options.conversations.some(
                                        (item) =>
                                            String(item.id) === conversation,
                                    ) && (
                                        <SelectItem
                                            value={conversation}
                                            disabled
                                        >
                                            Selected conversation unavailable
                                        </SelectItem>
                                    )}
                                {options.conversations.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        {item.title || 'Conversation'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                {(query || sender || conversation || date !== 'any') && (
                    <Button
                        variant="ghost"
                        className="mb-3 min-h-[44px]"
                        onClick={() => {
                            setQuery('');
                            setSender('');
                            setDate('any');
                            setConversation('');
                        }}
                    >
                        Clear filters
                    </Button>
                )}
                {error && (
                    <div
                        role="alert"
                        className="mb-4 rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        {error.message}
                        <Button
                            variant="ghost"
                            className="min-h-[44px]"
                            disabled={busy}
                            onClick={() =>
                                error.source === 'more' &&
                                snapshot.current?.next_before
                                    ? void more()
                                    : setRetry((value) => value + 1)
                            }
                        >
                            Retry search
                        </Button>
                    </div>
                )}
                {busy && !page && (
                    <div role="status">
                        <LoadingState message="Searching…" className="py-8" />
                    </div>
                )}
                {busy && page && (
                    <p
                        role="status"
                        className="mb-3 flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Loader2 className="size-4 animate-spin" />
                        Updating results…
                    </p>
                )}
                <Card
                    className="gap-0 overflow-hidden rounded-xl py-0 shadow-none"
                    aria-label="Search results"
                >
                    {page?.items.map((item) => (
                        <Button
                            variant="ghost"
                            key={item.id}
                            data-search-result={item.id}
                            className="flex h-auto min-h-[64px] w-full flex-col items-start gap-[5px] rounded-none border-b px-4 py-[15px] text-left break-words whitespace-normal last:border-0 hover:bg-muted/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                            onClick={() => {
                                returnTo.current = {
                                    id: item.id,
                                    top: container.current?.scrollTop ?? 0,
                                };
                                onSelect(item);
                            }}
                        >
                            <strong className="text-[14px]">
                                {item.kind === 'person'
                                    ? item.name
                                    : item.conversation.title || 'Conversation'}
                                {item.message &&
                                    ` · ${item.message.sender_name}`}
                            </strong>
                            <span className="text-[14px] whitespace-pre-wrap text-muted-foreground">
                                {item.kind === 'file'
                                    ? [
                                          ...(item.message?.attachments ?? []),
                                          ...(item.message
                                              ?.legacy_attachments ?? []),
                                      ]
                                          .map((file) => file.name)
                                          .join(', ')
                                    : (item.message?.content ??
                                      (item.kind === 'person'
                                          ? item.conversation.title
                                          : item.conversation.type === 'family'
                                            ? 'Family conversation'
                                            : item.conversation.type ===
                                                'channel'
                                              ? 'Team channel'
                                              : 'Conversation'))}
                            </span>
                            {item.message && (
                                <small className="text-[12px] text-muted-foreground">
                                    {formatDateTime(item.message.created_at)} ·
                                    Open exact message
                                </small>
                            )}
                        </Button>
                    ))}
                    {page && !page.items.length && !busy && (
                        <div className="space-y-2 p-8 text-center">
                            <Search className="mx-auto size-6 text-muted-foreground" />
                            <h3 className="font-semibold">No matches</h3>
                            <p className="text-sm text-muted-foreground">
                                Try fewer words or clear a filter.
                            </p>
                        </div>
                    )}
                </Card>
                {page?.next_before && (
                    <Button
                        variant="outline"
                        disabled={busy}
                        className="mt-4 min-h-[44px]"
                        onClick={() => void more()}
                    >
                        Load more results
                    </Button>
                )}
            </div>
        </section>
    );
}

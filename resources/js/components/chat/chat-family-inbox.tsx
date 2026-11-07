import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { ArrowLeft, CheckCheck, Filter, LogOut } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { ChatError } from './api';
import { readCollectionPage } from './chat-collection-sync';
import { ChatResponseClock, type ResponseClock } from './chat-response-clock';
import { ChatThread } from './chat-thread';
import { watchChatUpdates } from './chat-update-watch';
import type { ChatPolicy } from './types';

export const familyInboxFilters = [
    ['open', 'Needs reply'],
    ['mine', 'Assigned to me'],
    ['waiting', 'Waiting on family'],
    ['callbacks', 'Callbacks'],
    ['resolved', 'Resolved'],
] as const;
export type FamilyInboxRow = {
    id: number;
    title: string;
    client: { id: number; name: string };
    status: string;
    assigned_to: number | null;
    owner_name: string | null;
    callback_count: number;
    response_expectation: string;
    response_clock?: ResponseClock;
};
export type FamilyInboxPage = {
    revision?: string;
    items: FamilyInboxRow[];
    counts: Record<string, number>;
    next_before: number | null;
    scan_floor: number | null;
    older_before: number | null;
    selected_available: boolean | null;
    settings: ChatPolicy;
};
function rows(items: FamilyInboxRow[]) {
    // The server owns response priority; conversation IDs are only stable identities.
    return [...new Map(items.map((item) => [item.id, item])).values()];
}
async function readInboxPage(
    path: string,
    signal: AbortSignal,
    actorId: number,
): Promise<FamilyInboxPage> {
    const page = await readCollectionPage<FamilyInboxPage>(path, signal, {
        'X-Chat-Actor': String(actorId),
    });
    const cursor = (value: unknown) =>
        value === null ||
        (typeof value === 'number' && Number.isSafeInteger(value) && value > 0);
    if (
        !page ||
        !Array.isArray(page.items) ||
        page.items.length > 30 ||
        page.items.some(
            (item) => !item || !Number.isSafeInteger(item.id) || item.id < 1,
        ) ||
        new Set(page.items.map((item) => item.id)).size !== page.items.length ||
        ![page.next_before, page.scan_floor, page.older_before].every(cursor) ||
        (page.revision !== undefined && !/^[a-f0-9]{64}$/.test(page.revision))
    )
        throw new Error(
            'The family queue result could not be verified. Please refresh again.',
        );
    return page;
}
export async function refreshFamilyInbox(
    path: string,
    previous: FamilyInboxPage | null,
    signal: AbortSignal,
    actorId: number,
): Promise<FamilyInboxPage> {
    const floor = previous?.scan_floor ?? previous?.items.at(-1)?.id;
    if (!floor) return readInboxPage(path, signal, actorId);
    let before: number | null = null;
    let first: FamilyInboxPage | null = null;
    let result: FamilyInboxPage;
    const items: FamilyInboxRow[] = [];
    const cursors = new Set<number>();
    const seen = new Set<number>();
    do {
        result = await readInboxPage(
            `${path}&through=${floor}&minimum=${previous?.items.length ?? 30}${before ? `&before=${before}` : ''}${first?.revision ? `&revision=${first.revision}` : ''}`,
            signal,
            actorId,
        );
        first ??= result;
        if (
            result.revision !== first.revision ||
            result.items.some((item) => seen.has(item.id))
        )
            throw new Error(
                'Family work changed while refreshing. Please refresh the queue again.',
            );
        result.items.forEach((item) => seen.add(item.id));
        items.push(...result.items);
        if (
            result.next_before !== null &&
            (cursors.has(result.next_before) ||
                result.next_before !== result.items.at(-1)?.id)
        )
            throw new Error(
                'The family inbox could not be refreshed. Please try again.',
            );
        before = result.next_before;
        if (before !== null) cursors.add(before);
    } while (before !== null);
    return {
        ...first,
        items: rows(items),
        scan_floor: items.at(-1)?.id ?? null,
        next_before: result.older_before,
    };
}
type Props = {
    actorId: number;
    clientId?: number | null;
    initialConversation?: number | null;
    initialFilter?: (typeof familyInboxFilters)[number][0];
    exitLabel?: string;
    onExit: () => void;
};
export function ChatFamilyInbox(props: Props) {
    return (
        <FamilyInboxSession
            key={`${props.actorId}:${props.clientId}:${props.initialFilter ?? 'open'}`}
            {...props}
        />
    );
}
function FamilyInboxSession({
    actorId,
    clientId,
    initialConversation,
    initialFilter = 'open',
    exitLabel = 'Back to Chat',
    onExit,
}: Props) {
    const [selected, setSelected] = useState<number | null>(
        initialConversation ?? null,
    );
    const [showList, setShowList] = useState(!initialConversation);
    const [filter, setFilter] = useState<string>(initialFilter);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [page, setPage] = useState<FamilyInboxPage | null>(null);
    const [error, setError] = useState<{
        message: string;
        more?: boolean;
        refresh?: boolean;
    } | null>(null);
    const [loading, setLoading] = useState(false);
    const [retry, setRetry] = useState(0);
    const [handoff, setHandoff] = useState(0);
    const handoffSequence = useRef(0);
    const current = useRef<FamilyInboxPage | null>(null);
    const request = useRef<AbortController | null>(null);
    const list = useRef<HTMLDivElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const anchor = useRef<{ id: string; offset: number } | null>(null);
    const scope = useRef(filter);
    const path = `/chat/api/family-inbox?filter=${filter}${clientId ? `&client=${clientId}` : ''}${selected ? `&selected=${selected}` : ''}`;
    function replace(next: FamilyInboxPage | null) {
        const area = list.current;
        if (area && area.scrollTop > 2) {
            const top = area.getBoundingClientRect().top;
            const visible = [
                ...area.querySelectorAll<HTMLElement>('[data-inbox-row]'),
            ].find((item) => item.getBoundingClientRect().bottom > top);
            anchor.current = visible
                ? {
                      id: visible.dataset.inboxRow!,
                      offset: visible.getBoundingClientRect().top - top,
                  }
                : null;
        }
        current.current = next;
        setPage(next);
    }
    const failure = useCallback((error: unknown, more = false) => {
        if (
            error instanceof ChatError &&
            [401, 403, 404, 419].includes(error.status)
        ) {
            replace(null);
            setSelected(null);
            setHandoff(0);
            setShowList(true);
        }
        setError({
            message: (error as Error).message,
            more,
            refresh: error instanceof ChatError && error.status === 409,
        });
    }, []);
    useLayoutEffect(() => {
        if (list.current && anchor.current) {
            const row = list.current.querySelector<HTMLElement>(
                `[data-inbox-row="${anchor.current.id}"]`,
            );
            if (row)
                list.current.scrollTop +=
                    row.getBoundingClientRect().top -
                    list.current.getBoundingClientRect().top -
                    anchor.current.offset;
        }
        anchor.current = null;
    }, [page]);
    useLayoutEffect(() => {
        if (showList) {
            const target = selected
                ? list.current?.querySelector<HTMLElement>(
                      `[data-inbox-row="${selected}"]`,
                  )
                : null;
            (target ?? heading.current)?.focus({ preventScroll: true });
        }
    }, [showList, selected]);
    useEffect(() => {
        if (scope.current !== filter) {
            scope.current = filter;
            replace(null);
            setError(null);
        }
        const stop = watchChatUpdates(
            async () => {
                if (request.current) return false;
                const controller = new AbortController();
                request.current = controller;
                setLoading(true);
                try {
                    const next = await refreshFamilyInbox(
                        path,
                        current.current,
                        controller.signal,
                        actorId,
                    );
                    if (controller.signal.aborted) return false;
                    replace(next);
                    setError((previous) =>
                        previous?.more && !previous.refresh ? previous : null,
                    );
                    if (next.selected_available === false) {
                        setSelected(null);
                        setShowList(true);
                    }
                    return true;
                } catch (error) {
                    if (!controller.signal.aborted) failure(error);
                    return false;
                } finally {
                    if (request.current === controller) {
                        request.current = null;
                        setLoading(false);
                    }
                }
            },
            // Live: refreshed when a conversation or the list changes; polled only while down.
            { topics: ['conversation.changed', 'inbox.changed'] },
        );
        return () => {
            stop();
            request.current?.abort();
            request.current = null;
        };
    }, [actorId, filter, path, retry, failure]);
    async function older() {
        const previous = current.current;
        if (!previous?.next_before || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        try {
            const next = await readInboxPage(
                `${path}&before=${previous.next_before}${previous.revision ? `&revision=${previous.revision}` : ''}`,
                controller.signal,
                actorId,
            );
            if (!controller.signal.aborted) {
                if (next.revision !== previous.revision)
                    throw new ChatError(
                        'Family work changed. Refresh the queue before loading more.',
                        409,
                    );
                const loaded = new Set(previous.items.map((item) => item.id));
                if (
                    next.items.some((item) => loaded.has(item.id)) ||
                    (next.next_before !== null &&
                        (loaded.has(next.next_before) ||
                            next.next_before !== next.items.at(-1)?.id))
                )
                    throw new ChatError(
                        'Family work changed. Refresh the queue before loading more.',
                        409,
                    );
                replace({
                    ...next,
                    items: rows([...previous.items, ...next.items]),
                });
                setError(null);
            }
        } catch (error) {
            if (!controller.signal.aborted) failure(error, true);
        } finally {
            if (request.current === controller) {
                request.current = null;
                setLoading(false);
                setRetry((value) => value + 1);
            }
        }
    }
    const choose = (value: string) => {
        setHandoff(0);
        setFilter(value);
        setShowList(true);
        setFiltersOpen(false);
    };
    const filterButtons = familyInboxFilters.map(([value, label]) => (
        <Button
            variant="ghost"
            key={value}
            type="button"
            aria-pressed={filter === value}
            aria-label={`${label} ${page?.counts[value] ?? '—'}`}
            onClick={() => choose(value)}
            className={cn(
                'frontline-focus flex min-h-[44px] shrink-0 items-center justify-between gap-2 rounded-lg px-2.5 text-left text-[13px] whitespace-nowrap',
                filter === value
                    ? 'bg-primary-fill/10 font-semibold text-primary'
                    : 'hover:bg-muted',
            )}
        >
            <span>{label}</span>
            <span>{page?.counts[value] ?? '—'}</span>
        </Button>
    ));
    return (
        <div className="flex min-h-0 flex-1 overflow-hidden">
            <aside
                aria-label="Family inbox"
                className={cn(
                    'flex w-full shrink-0 flex-col gap-4 border-r px-3 pt-[18px] pb-3 md:w-[280px] xl:w-[310px]',
                    !showList && 'hidden md:flex',
                )}
            >
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-[44px] shrink-0"
                        aria-label={exitLabel}
                        onClick={onExit}
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-section-title min-w-0 flex-1 outline-none"
                    >
                        Family inbox
                    </h2>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-[44px] shrink-0"
                        aria-label="Family inbox filters"
                        onClick={() => setFiltersOpen(true)}
                    >
                        <Filter className="size-4" />
                    </Button>
                </div>
                <div
                    aria-label="Family work queues"
                    className="flex shrink-0 gap-1 overflow-x-auto md:flex-col"
                >
                    {filterButtons}
                </div>
                {error && (
                    <div
                        role="alert"
                        className="rounded-md bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        <p>{error.message}</p>
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            disabled={loading}
                            onClick={() =>
                                error.more && !error.refresh
                                    ? void older()
                                    : setRetry((n) => n + 1)
                            }
                        >
                            {error.refresh ? 'Refresh queue' : 'Retry loading'}
                        </Button>
                    </div>
                )}
                <div ref={list} className="min-h-0 flex-1 overflow-y-auto">
                    {!page && !error && (
                        <div role="status">
                            <LoadingState
                                message="Loading family work…"
                                className="py-8"
                            />
                        </div>
                    )}
                    {page?.items.map((item) => (
                        <Button
                            variant="ghost"
                            key={item.id}
                            data-inbox-row={item.id}
                            type="button"
                            aria-current={
                                selected === item.id ? 'true' : undefined
                            }
                            onClick={() => {
                                setSelected(item.id);
                                setShowList(false);
                                setHandoff(0);
                            }}
                            className={cn(
                                'frontline-focus mb-2 block h-auto w-full rounded-[10px] border p-[15px] text-left whitespace-normal md:border-transparent md:px-2.5',
                                selected === item.id
                                    ? 'border-border bg-primary-fill/10 text-primary'
                                    : 'hover:bg-muted',
                            )}
                        >
                            <div className="flex items-center gap-3">
                                <span
                                    aria-hidden
                                    className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-status-success-bg text-xs font-semibold text-status-success"
                                >
                                    {item.title
                                        .split(' ')
                                        .slice(0, 2)
                                        .map((part) => part[0])
                                        .join('')}
                                </span>
                                <div className="min-w-0">
                                    <strong className="block text-sm break-words">
                                        {item.title}
                                    </strong>
                                    <p className="text-xs text-muted-foreground">
                                        About {item.client.name}
                                    </p>
                                </div>
                            </div>
                            <div className="mt-[13px] mb-[7px] flex flex-col gap-1 text-xs">
                                {item.owner_name ? (
                                    <span>Owner: {item.owner_name}</span>
                                ) : (
                                    <span>
                                        <StatusBadge
                                            size="sm"
                                            variant="warning"
                                        >
                                            Unassigned · Claim needed
                                        </StatusBadge>
                                    </span>
                                )}
                                <span className="text-muted-foreground">
                                    <ChatResponseClock
                                        clock={item.response_clock}
                                        wording={item.response_expectation}
                                    />
                                </span>
                                {item.callback_count > 0 && (
                                    <span>
                                        {item.callback_count} open callback{' '}
                                        {item.callback_count === 1
                                            ? 'request'
                                            : 'requests'}
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {item.status === 'resolved'
                                    ? 'No action outstanding'
                                    : item.status === 'waiting'
                                      ? 'Next: wait for family’s reply'
                                      : 'Next: read and respond'}
                            </p>
                        </Button>
                    ))}
                    {page && !page.items.length && (
                        <div className="space-y-2 p-5 text-center text-sm">
                            <CheckCheck className="mx-auto size-6 text-muted-foreground" />
                            <h3 className="font-semibold">
                                No work in this view
                            </h3>
                            <p className="text-muted-foreground">
                                Choose another filter to see other family
                                conversations.
                            </p>
                        </div>
                    )}
                    {page?.next_before && (
                        <Button
                            variant="outline"
                            className="min-h-[44px] w-full"
                            disabled={loading}
                            onClick={() => void older()}
                        >
                            More conversations
                        </Button>
                    )}
                </div>
                <Button
                    variant="outline"
                    className="min-h-[44px] shrink-0 gap-2 text-xs"
                    disabled={
                        !selected || !page || page.selected_available === false
                    }
                    onClick={() => {
                        setShowList(false);
                        setHandoff(++handoffSequence.current);
                    }}
                >
                    <LogOut className="size-4" />
                    Hand over before shift end
                </Button>
            </aside>
            <div
                className={cn(
                    'min-h-0 min-w-0 flex-1 flex-col',
                    showList ? 'hidden md:flex' : 'flex',
                )}
            >
                {selected && page ? (
                    <ChatThread
                        key={`${actorId}:${selected}`}
                        conversationId={selected}
                        actorId={actorId}
                        policy={page.settings}
                        handoffRequest={handoff}
                        familyInbox
                        onBack={() => setShowList(true)}
                        onChange={() => setRetry((n) => n + 1)}
                    />
                ) : (
                    <div className="m-auto p-8 text-center text-sm text-muted-foreground">
                        Choose a family conversation to review and respond.
                    </div>
                )}
            </div>
            <Dialog open={filtersOpen} onOpenChange={setFiltersOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    aria-describedby={undefined}
                >
                    <DialogHeader>
                        <DialogTitle>Family inbox filters</DialogTitle>
                    </DialogHeader>
                    <div className="flex flex-col gap-1">{filterButtons}</div>
                </DialogContent>
            </Dialog>
        </div>
    );
}

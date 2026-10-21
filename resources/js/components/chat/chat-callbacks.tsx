import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import { formatDateTime } from '@/lib/datetime';
import { PhoneCall } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import {
    ChatError,
    chatDraftKey,
    chatRequest,
    conversationPath,
    isChatAccessDenied,
    readChatCallbackMemory,
    saveChatCallbackMemory,
    type ChatCallbackPending,
} from './api';
import { CallbackActions } from './callback-actions';
import {
    callbackRows,
    callbackStatus,
    refreshCallbacks,
    type ChatCallback,
    type ChatCallbackPage,
} from './chat-callback-sync';
import { readCollectionPage } from './chat-collection-sync';
import { useChatRealtime } from './chat-realtime';
import { watchChatUpdates } from './chat-update-watch';

export function ChatCallbacks(props: {
    conversationId: number;
    actorId: number;
    family: boolean;
    compact?: boolean;
    controlledOpen?: boolean;
    hideTrigger?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    return (
        <CallbackSession
            key={`${props.actorId}:${props.conversationId}`}
            {...props}
        />
    );
}
function CallbackSession({
    conversationId,
    actorId,
    family,
    compact = false,
    controlledOpen,
    hideTrigger = false,
    onOpenChange,
}: {
    conversationId: number;
    actorId: number;
    family: boolean;
    compact?: boolean;
    controlledOpen?: boolean;
    hideTrigger?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const memoryKey = chatDraftKey(actorId, null, conversationId);
    const initial = readChatCallbackMemory(memoryKey);
    const [localOpen, setLocalOpen] = useState(false);
    const open = controlledOpen ?? localOpen;
    const setOpen = (value: boolean) => {
        if (controlledOpen === undefined) setLocalOpen(value);
        onOpenChange?.(value);
    };
    const [page, setPage] = useState<ChatCallbackPage | null>(null);
    const [preferred, setPreferred] = useState(initial.preferred);
    const [note, setNote] = useState(initial.note);
    const [pending, setPending] = useState<ChatCallbackPending | null>(
        initial.pending,
    );
    const [writing, setWriting] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<{
        kind: 'read' | 'write';
        message: string;
        more?: boolean;
    } | null>(null);
    const [retry, setRetry] = useState(0);
    const request = useRef<AbortController | null>(null);
    const mutation = useRef<AbortController | null>(null);
    const mounted = useRef(true);
    const snapshot = useRef<ChatCallbackPage | null>(null);
    const scrollArea = useRef<HTMLDivElement>(null);
    const title = useRef<HTMLHeadingElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const retryButton = useRef<HTMLButtonElement>(null);
    const focusResult = useRef<number | null>(null);
    const position = useRef<{
        rows: { id: string; offset: number }[];
        focused: string | null;
    } | null>(null);
    const busy = useRef(false);
    const writeBusy = useRef(false);
    const queued = useRef(false);
    const realtime = useChatRealtime();
    const realtimeRef = useRef(realtime);
    const path = `${conversationPath(conversationId)}/callbacks`;
    useEffect(() => {
        realtimeRef.current = realtime;
    }, [realtime]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            mutation.current?.abort();
        };
    }, []);
    function remember(next: ChatCallbackPending | null) {
        saveChatCallbackMemory(memoryKey, { pending: next });
        setPending(next);
    }
    const replace = useCallback((next: ChatCallbackPage | null) => {
        const area = scrollArea.current;
        if (area) {
            const top = area.getBoundingClientRect().top;
            const rows = Array.from(
                area.querySelectorAll<HTMLElement>('[data-callback-row]'),
            );
            position.current = {
                rows: rows
                    .filter((row) => row.getBoundingClientRect().bottom > top)
                    .map((row) => ({
                        id: row.dataset.callbackRow!,
                        offset: row.getBoundingClientRect().top - top,
                    })),
                focused:
                    rows.find((row) => row.contains(document.activeElement))
                        ?.dataset.callbackRow ?? null,
            };
        }
        snapshot.current = next;
        setPage(next);
    }, []);
    useLayoutEffect(() => {
        const saved = position.current;
        const area = scrollArea.current;
        position.current = null;
        if (!area) return;
        if (focusResult.current !== null) {
            (
                area.querySelector<HTMLElement>(
                    `[data-callback-heading="${focusResult.current}"]`,
                ) ?? title.current
            )?.focus();
            focusResult.current = null;
            return;
        }
        if (!saved) return;
        const rows = Array.from(
            area.querySelectorAll<HTMLElement>('[data-callback-row]'),
        );
        const anchor = saved.rows.find((item) =>
            rows.some((row) => row.dataset.callbackRow === item.id),
        );
        const row = rows.find((row) => row.dataset.callbackRow === anchor?.id);
        if (row && anchor)
            area.scrollTop +=
                row.getBoundingClientRect().top -
                area.getBoundingClientRect().top -
                anchor.offset;
        if (
            saved.focused &&
            !rows.some((row) => row.dataset.callbackRow === saved.focused)
        )
            (row?.querySelector<HTMLElement>('h3') ?? title.current)?.focus({
                preventScroll: true,
            });
    }, [page]);
    useEffect(() => {
        if (open && pending && !writing) retryButton.current?.focus();
    }, [open, pending, writing]);
    const readFailure = useCallback(
        (failure: unknown, more = false) => {
            const denied = isChatAccessDenied(failure);
            if (denied) {
                replace(null);
                setPreferred('');
                setNote('');
                setPending(null);
                saveChatCallbackMemory(memoryKey, {
                    preferred: '',
                    note: '',
                    pending: null,
                });
            }
            setError((current) =>
                !denied && current?.kind === 'write'
                    ? current
                    : {
                          kind: 'read',
                          message: (failure as Error).message,
                          more,
                      },
            );
        },
        [replace, memoryKey],
    );
    useEffect(() => {
        const controller = new AbortController();
        request.current = controller;
        busy.current = false;
        queued.current = false;
        if (!open) {
            setPage(null);
            return () => controller.abort();
        }
        setLoading(true);
        const stop = watchChatUpdates(
            async () => {
                if (busy.current || writeBusy.current) {
                    queued.current = true;
                    return true;
                }
                busy.current = true;
                setLoading(true);
                try {
                    const result = await refreshCallbacks(
                        path,
                        snapshot.current,
                        controller.signal,
                        actorId,
                    );
                    if (controller.signal.aborted) return false;
                    replace(result);
                    setError((current) =>
                        current?.kind === 'write' ||
                        (current?.more && result.next_before !== null)
                            ? current
                            : null,
                    );
                    return true;
                } catch (failure) {
                    if (!controller.signal.aborted) readFailure(failure);
                    return false;
                } finally {
                    if (!controller.signal.aborted) {
                        busy.current = false;
                        setLoading(false);
                    }
                }
            },
            {
                interval: () =>
                    realtimeRef.current === 'live' ? 30000 : 10000,
                events: [
                    'oblivion:chat-refresh',
                    'oblivion:chat-callback-refresh',
                ],
            },
        );
        return () => {
            stop();
            controller.abort();
        };
    }, [open, path, retry, replace, readFailure, actorId]);
    async function more() {
        const current = snapshot.current;
        const controller = request.current;
        if (
            !current?.next_before ||
            !controller ||
            controller.signal.aborted ||
            busy.current ||
            writeBusy.current
        )
            return;
        busy.current = true;
        setLoading(true);
        try {
            const next = await readCollectionPage<ChatCallbackPage>(
                `${path}?before=${current.next_before}`,
                controller.signal,
                { 'X-Chat-Actor': String(actorId) },
            );
            if (controller.signal.aborted) return;
            if (
                next.next_before !== null &&
                next.next_before >= current.next_before
            )
                throw new Error('The callback list changed. Please try again.');
            replace({
                ...next,
                callbacks: callbackRows([
                    ...current.callbacks,
                    ...next.callbacks,
                ]),
                scan_floor: next.scan_floor ?? current.scan_floor,
            });
            setError((current) => (current?.kind === 'write' ? current : null));
        } catch (failure) {
            if (!controller.signal.aborted) readFailure(failure, true);
        } finally {
            if (!controller.signal.aborted) {
                busy.current = false;
                setLoading(false);
                if (queued.current) {
                    queued.current = false;
                    window.dispatchEvent(
                        new Event('oblivion:chat-callback-refresh'),
                    );
                }
            }
        }
    }
    async function send(command: ChatCallbackPending): Promise<boolean> {
        if (writeBusy.current) return false;
        writeBusy.current = true;
        request.current?.abort();
        remember(command);
        setWriting(true);
        setError(null);
        const controller = new AbortController();
        mutation.current = controller;
        const timer = setTimeout(() => controller.abort(), 10000);
        try {
            const result = await chatRequest<
                { id?: number; already_requested?: boolean } | undefined
            >(command.path, {
                method: command.method,
                body: command.body,
                signal: controller.signal,
                headers: { 'X-Chat-Actor': String(actorId) },
            });
            if (!mounted.current) return false;
            remember(null);
            focusResult.current =
                command.method === 'PATCH'
                    ? Number(command.path.split('/').at(-1))
                    : (result?.id ?? null);
            if (command.method === 'POST') {
                setPreferred('');
                setNote('');
                saveChatCallbackMemory(memoryKey, { preferred: '', note: '' });
            }
            toast.success(
                result?.already_requested
                    ? 'Your existing callback request is shown.'
                    : command.method === 'POST'
                      ? 'Callback requested.'
                      : 'Callback updated.',
            );
            return true;
        } catch (failure) {
            if (!mounted.current) return false;
            if (
                failure instanceof ChatError &&
                [401, 403, 404, 419, 409, 422].includes(failure.status)
            ) {
                remember(null);
                if (isChatAccessDenied(failure)) {
                    replace(null);
                    setPreferred('');
                    setNote('');
                    saveChatCallbackMemory(memoryKey, {
                        preferred: '',
                        note: '',
                        pending: null,
                    });
                }
                setError({ kind: 'write', message: failure.message });
            } else
                setError({
                    kind: 'write',
                    message:
                        'This update has not been confirmed. Retry the same update to check safely; your details are kept.',
                });
            return false;
        } finally {
            clearTimeout(timer);
            writeBusy.current = false;
            if (mounted.current) {
                setWriting(false);
                queued.current = false;
                setRetry((value) => value + 1);
                window.dispatchEvent(
                    new Event('oblivion:chat-callback-refresh'),
                );
            }
        }
    }
    function update(
        row: ChatCallback,
        action: string,
        data: Record<string, unknown> = {},
    ) {
        return send({
            path: `/chat/api/callbacks/${row.id}`,
            method: 'PATCH',
            label: 'Retry callback update',
            body: JSON.stringify({
                mutation_id: crypto.randomUUID(),
                version: row.version,
                action,
                ...data,
            }),
        });
    }
    const current = page?.current_callback;
    const rows = current
        ? [
              current,
              ...(page?.callbacks ?? []).filter((row) => row.id !== current.id),
          ]
        : (page?.callbacks ?? []);
    return (
        <>
            {!hideTrigger && (
                <Button
                    variant="outline"
                    size="sm"
                    className={
                        compact ? 'size-[44px] shrink-0 p-0' : 'min-h-[44px]'
                    }
                    aria-label={family ? 'Request callback' : 'Callbacks'}
                    title={family ? 'Request callback' : 'Callbacks'}
                    ref={trigger}
                    onClick={() => setOpen(true)}
                >
                    <PhoneCall className="size-4" />
                    <span className={compact ? 'sr-only' : undefined}>
                        {family ? 'Request callback' : 'Callbacks'}
                    </span>
                </Button>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0"
                    onCloseAutoFocus={(event) => {
                        if (trigger.current?.isConnected) {
                            event.preventDefault();
                            trigger.current.focus({ preventScroll: true });
                        }
                    }}
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-12">
                        <DialogTitle ref={title} tabIndex={-1}>
                            {current
                                ? 'Callback request'
                                : family
                                  ? 'Request a callback'
                                  : 'Callback requests'}
                        </DialogTitle>
                        <DialogDescription>
                            A preferred time is not an agreed appointment. The
                            care team will confirm availability.
                        </DialogDescription>
                    </DialogHeader>
                    <div
                        ref={scrollArea}
                        aria-busy={loading}
                        className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5"
                    >
                        {error && (
                            <div
                                role="alert"
                                className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                            >
                                {error.message}
                                {error.kind === 'read' && (
                                    <Button
                                        variant="ghost"
                                        className="min-h-[44px]"
                                        disabled={loading || writing}
                                        onClick={() =>
                                            error.more
                                                ? void more()
                                                : setRetry((value) => value + 1)
                                        }
                                    >
                                        Retry loading
                                    </Button>
                                )}
                            </div>
                        )}
                        {pending && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p>
                                    {pending.label}. The same request reference
                                    will be used.
                                </p>
                                <Button
                                    ref={retryButton}
                                    className="mt-2 min-h-[44px]"
                                    disabled={writing}
                                    onClick={() => void send(pending)}
                                >
                                    {writing
                                        ? 'Checking update…'
                                        : pending.label}
                                </Button>
                            </div>
                        )}
                        {loading && !page && (
                            <div role="status">
                                <LoadingState
                                    message="Loading callback requests…"
                                    className="py-8"
                                />
                            </div>
                        )}
                        {rows.map((row) => (
                            <Card
                                key={row.id}
                                data-callback-row={row.id}
                                className="gap-3 p-4 shadow-none"
                            >
                                <h3
                                    tabIndex={-1}
                                    data-callback-heading={row.id}
                                    className="text-sm font-semibold"
                                >
                                    {callbackStatus[row.status] ??
                                        'Callback request'}{' '}
                                    · Owner:{' '}
                                    {row.owner_name ?? 'Awaiting assignment'}
                                </h3>
                                <p className="text-sm">
                                    Preferred:{' '}
                                    {row.preferred_time ?? 'Not specified'}
                                </p>
                                <p className="text-sm">
                                    Agreed time:{' '}
                                    {row.agreed_time ?? 'Not agreed'}
                                </p>
                                {row.request_note && (
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {row.request_note}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Requested {formatDateTime(row.created_at)}
                                </p>
                                {row.attempt_history.map((attempt) => (
                                    <p key={attempt.id} className="text-sm">
                                        {formatDateTime(attempt.created_at)} ·
                                        No answer
                                    </p>
                                ))}
                                {row.attempts > row.attempt_history.length && (
                                    <p className="text-xs text-muted-foreground">
                                        {row.attempts -
                                            row.attempt_history.length}{' '}
                                        earlier contact attempts · dates not
                                        recorded
                                    </p>
                                )}
                                <CallbackActions
                                    row={row}
                                    owners={page?.owners ?? []}
                                    actorId={actorId}
                                    memoryKey={memoryKey}
                                    disabled={writing || !!pending}
                                    onSubmit={update}
                                />
                                {current?.id === row.id && (
                                    <p className="text-xs text-muted-foreground">
                                        This existing request is reused.
                                        Repeated requests do not create
                                        duplicates.
                                    </p>
                                )}
                            </Card>
                        ))}
                        {page?.next_before && (
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={loading || writing}
                                onClick={() => void more()}
                            >
                                Earlier callback requests
                            </Button>
                        )}
                        {page && !rows.length && !loading && (
                            <p className="text-sm text-muted-foreground">
                                No callback requests in this conversation.
                            </p>
                        )}
                        {family && page?.can_request && (
                            <>
                                <label className="block text-sm font-medium">
                                    Preferred time
                                    <Input
                                        className="mt-1 min-h-[44px]"
                                        value={preferred}
                                        maxLength={300}
                                        disabled={writing || !!pending}
                                        placeholder="For example, after 2 pm"
                                        onChange={(event) => {
                                            setPreferred(event.target.value);
                                            saveChatCallbackMemory(memoryKey, {
                                                preferred: event.target.value,
                                            });
                                        }}
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    Anything the team should know? (optional)
                                    <textarea
                                        rows={3}
                                        maxLength={1000}
                                        value={note}
                                        disabled={writing || !!pending}
                                        onChange={(event) => {
                                            setNote(event.target.value);
                                            saveChatCallbackMemory(memoryKey, {
                                                note: event.target.value,
                                            });
                                        }}
                                        className="mt-1 w-full rounded-lg border bg-background p-3 font-normal"
                                    />
                                </label>
                            </>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 border-t p-4">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                        {family && page?.can_request && (
                            <Button
                                className="min-h-[44px]"
                                disabled={
                                    loading ||
                                    writing ||
                                    !!pending ||
                                    !preferred.trim()
                                }
                                onClick={() =>
                                    void send({
                                        path,
                                        method: 'POST',
                                        label: 'Retry callback request',
                                        body: JSON.stringify({
                                            mutation_id: crypto.randomUUID(),
                                            preferred_time: preferred,
                                            request_note: note,
                                        }),
                                    })
                                }
                            >
                                {writing ? 'Requesting…' : 'Request callback'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

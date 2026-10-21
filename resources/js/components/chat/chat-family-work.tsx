import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/datetime';
import { LockKeyhole } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    ChatError,
    chatDraftKey,
    conversationPath,
    readFamilyWorkMemory,
    saveFamilyWorkMemory,
    type ChatFamilyWorkMemory,
} from './api';
import { chatActorRequest } from './chat-actor-request';
import { ChatCallbacks } from './chat-callbacks';
import { readCollectionPage } from './chat-collection-sync';
import { ChatFamilyFollowup } from './chat-family-followup';
import {
    familyWorkEvents,
    familyWorkStatus,
    refreshFamilyWork,
    type FamilyWorkPage,
} from './chat-family-work-sync';
import { ChatResponseClock } from './chat-response-clock';
import { watchChatUpdates } from './chat-update-watch';

type Props = {
    actorId: number;
    conversationId: number;
    clientName: string;
    onChange?: () => void;
    handoffRequest?: number;
    inbox?: boolean;
};
export function ChatFamilyWork(props: Props) {
    return (
        <FamilyWorkSession
            key={`${props.actorId}:${props.conversationId}`}
            {...props}
        />
    );
}
// Radix Select items cannot hold an empty value; "release to team queue" ('') maps to this.
const TEAM_QUEUE = '__none';

function FamilyWorkSession({
    actorId,
    conversationId,
    clientName,
    onChange,
    handoffRequest = 0,
    inbox = false,
}: Props) {
    const key = chatDraftKey(actorId, null, conversationId);
    const path = `${conversationPath(conversationId)}/family-work`;
    const [draft, setDraft] = useState(() => readFamilyWorkMemory(key));
    const fieldId = useId();
    const [page, setPage] = useState<FamilyWorkPage | null>(null);
    const [mode, setMode] = useState<
        'claim' | 'assign' | 'handoff' | 'note' | null
    >(null);
    const [error, setError] = useState<{
        message: string;
        write?: boolean;
        more?: boolean;
        code?: number;
    } | null>(null);
    const [writing, setWriting] = useState(false);
    const [loading, setLoading] = useState(false);
    const [retry, setRetry] = useState(0);
    const snapshot = useRef<FamilyWorkPage | null>(null);
    const read = useRef<AbortController | null>(null);
    const write = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const noteInput = useRef<HTMLTextAreaElement>(null);
    const focusNote = useRef(false);
    const historyArea = useRef<HTMLDivElement>(null);
    const historyAnchor = useRef<{ id: string; offset: number } | null>(null);
    const changed = useRef(onChange);
    const openedHandoff = useRef(0);
    useEffect(() => {
        if (!page || handoffRequest <= openedHandoff.current) return;
        openedHandoff.current = handoffRequest;
        if (draft.version === null) {
            const update = {
                owner: String(page.assigned_to ?? actorId),
                version: page.version,
            };
            saveFamilyWorkMemory(key, update);
            setDraft((current) => ({ ...current, ...update }));
        }
        setMode('handoff');
    }, [handoffRequest, page, draft.version, actorId, key]);
    changed.current = onChange;
    function remember(update: Partial<ChatFamilyWorkMemory>) {
        saveFamilyWorkMemory(key, update);
        setDraft((current) => ({ ...current, ...update }));
    }
    function replace(next: FamilyWorkPage | null) {
        const area = historyArea.current;
        if (area && area.scrollTop > 2) {
            const top = area.getBoundingClientRect().top;
            const row =
                document.activeElement === noteInput.current
                    ? noteInput.current
                    : [
                          ...area.querySelectorAll<HTMLElement>(
                              '[data-work-event]',
                          ),
                      ].find(
                          (item) => item.getBoundingClientRect().bottom > top,
                      );
            historyAnchor.current = row
                ? {
                      id: row.dataset.workEvent!,
                      offset: row.getBoundingClientRect().top - top,
                  }
                : null;
        }
        snapshot.current = next;
        setPage(next);
    }
    useLayoutEffect(() => {
        const area = historyArea.current;
        const anchor = historyAnchor.current;
        if (area && anchor) {
            const row = area.querySelector<HTMLElement>(
                `[data-work-event="${anchor.id}"]`,
            );
            if (row)
                area.scrollTop +=
                    row.getBoundingClientRect().top -
                    area.getBoundingClientRect().top -
                    anchor.offset;
        }
        historyAnchor.current = null;
    }, [page]);
    useLayoutEffect(() => {
        if (!writing && focusNote.current) {
            noteInput.current?.focus();
            focusNote.current = false;
        }
    }, [writing]);
    const readError = useCallback((failure: unknown, more = false) => {
        const denied =
            failure instanceof ChatError &&
            [401, 403, 404, 419].includes(failure.status);
        if (denied) replace(null);
        setError((current) =>
            !denied && current?.write
                ? current
                : { message: (failure as Error).message, more },
        );
    }, []);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            read.current?.abort();
            write.current?.abort();
        };
    }, []);
    useEffect(() => {
        const stop = watchChatUpdates(
            async () => {
                if (read.current || write.current) return false;
                const controller = new AbortController();
                read.current = controller;
                setLoading(true);
                try {
                    const next = await refreshFamilyWork(
                        path,
                        snapshot.current,
                        controller.signal,
                        actorId,
                    );
                    if (controller.signal.aborted || !alive.current)
                        return false;
                    replace(next);
                    setError((current) =>
                        current?.write || current?.more ? current : null,
                    );
                    return true;
                } catch (failure) {
                    if (!controller.signal.aborted && alive.current)
                        readError(failure);
                    return false;
                } finally {
                    if (read.current === controller) {
                        read.current = null;
                        if (alive.current) setLoading(false);
                    }
                }
            },
            { interval: () => 10000 },
        );
        return () => {
            stop();
            read.current?.abort();
            read.current = null;
        };
    }, [actorId, path, retry, readError]);
    async function older() {
        const previous = snapshot.current;
        if (!previous?.next_before || read.current || write.current) return;
        const controller = new AbortController();
        read.current = controller;
        setLoading(true);
        try {
            const next = await readCollectionPage<FamilyWorkPage>(
                `${path}?before=${previous.next_before}`,
                controller.signal,
                { 'X-Chat-Actor': String(actorId) },
            );
            if (!controller.signal.aborted && alive.current) {
                replace({
                    ...next,
                    events: familyWorkEvents([
                        ...previous.events,
                        ...next.events,
                    ]),
                });
                setError((current) => (current?.write ? current : null));
            }
        } catch (failure) {
            if (!controller.signal.aborted && alive.current)
                readError(failure, true);
        } finally {
            if (read.current === controller) {
                read.current = null;
                if (alive.current) {
                    setLoading(false);
                    setRetry((n) => n + 1);
                }
            }
        }
    }
    async function submit(body: string) {
        if (write.current) return;
        remember({ pending: body });
        read.current?.abort();
        read.current = null;
        const controller = new AbortController();
        write.current = controller;
        setWriting(true);
        setError(null);
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            await chatActorRequest(actorId, path, {
                method: 'PATCH',
                body,
                signal: controller.signal,
            });
            if (!alive.current) return;
            const action = JSON.parse(body).action;
            focusNote.current = action === 'note';
            remember({
                pending: null,
                ...(action === 'note'
                    ? { coordination: '' }
                    : action === 'status'
                      ? {}
                      : { note: '', version: null }),
            });
            if (action !== 'note') setMode(null);
            changed.current?.();
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
        } catch (failure) {
            if (!alive.current) return;
            const definitive =
                failure instanceof ChatError &&
                [401, 403, 404, 409, 422].includes(failure.status);
            if (definitive) remember({ pending: null });
            if (
                failure instanceof ChatError &&
                [401, 403, 404, 419].includes(failure.status)
            )
                replace(null);
            setError({
                message: definitive
                    ? (failure as Error).message
                    : 'This update could not be confirmed. Retry the same update to check whether it was saved.',
                write: true,
                code: failure instanceof ChatError ? failure.status : undefined,
            });
        } finally {
            clearTimeout(timeout);
            if (write.current === controller) write.current = null;
            if (alive.current) {
                setWriting(false);
                setLoading(false);
                setRetry((n) => n + 1);
            }
        }
    }
    function open(next: NonNullable<typeof mode>) {
        if (draft.version === null && page)
            remember({
                owner: String(page.assigned_to ?? actorId),
                version: page.version,
            });
        setMode(next);
    }
    const conflict =
        draft.version !== null && page && draft.version !== page.version;
    const disabled = !page?.can_manage || writing || !!draft.pending;
    const feedback = (
        <>
            {error &&
                !(
                    error.code === 409 &&
                    conflict &&
                    mode &&
                    mode !== 'note'
                ) && (
                    <div
                        role="alert"
                        className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        <p>{error.message}</p>
                        {!error.write && (
                            <Button
                                variant="outline"
                                className="mt-2 min-h-[44px]"
                                disabled={loading}
                                onClick={() =>
                                    error.more
                                        ? void older()
                                        : setRetry((n) => n + 1)
                                }
                            >
                                Retry loading
                            </Button>
                        )}
                    </div>
                )}
            {draft.pending && (
                <Button
                    variant="outline"
                    className="min-h-[44px]"
                    disabled={writing || !page}
                    onClick={() => void submit(draft.pending!)}
                >
                    Retry saved update
                </Button>
            )}
        </>
    );
    return (
        <>
            <div className="shrink-0 border-b">
                <div className="flex flex-wrap items-center gap-2 px-4 py-3">
                    <div className="min-w-[160px] flex-1">
                        <p className="text-sm font-semibold">
                            {page
                                ? page.owner_name
                                    ? `Owned by ${page.owner_name}`
                                    : 'No owner assigned'
                                : error
                                  ? 'Family work unavailable'
                                  : 'Loading family work…'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            <ChatResponseClock
                                clock={page?.response_clock}
                                wording={page?.response_expectation}
                            />
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={!page}
                        onClick={() =>
                            open(page?.assigned_to ? 'assign' : 'claim')
                        }
                    >
                        {page?.assigned_to ? 'Reassign / release' : 'Claim'}
                    </Button>
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={!page}
                        onClick={() => open('note')}
                    >
                        Staff coordination
                    </Button>
                    {!inbox && (
                        <Button
                            variant="ghost"
                            className="min-h-[44px]"
                            disabled={!page}
                            onClick={() => open('handoff')}
                        >
                            Hand over
                        </Button>
                    )}
                </div>
                {page && (
                    <div
                        className={
                            inbox
                                ? 'flex flex-wrap items-center gap-2 border-t px-3 py-2 text-[12px]'
                                : 'flex flex-wrap items-center gap-3 border-t px-4 py-2 text-sm'
                        }
                    >
                        <span className="font-medium">
                            {familyWorkStatus[page.status]}
                        </span>
                        <Select
                            value={page.status}
                            disabled={disabled}
                            onValueChange={(status) =>
                                void submit(
                                    JSON.stringify({
                                        mutation_id: crypto.randomUUID(),
                                        action: 'status',
                                        version: page.version,
                                        status,
                                    }),
                                )
                            }
                        >
                            <SelectTrigger
                                aria-label="Family work status"
                                className="min-h-[44px] w-auto bg-background"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(familyWorkStatus).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        {!inbox && (
                            <span className="text-xs text-muted-foreground">
                                {page.status === 'waiting'
                                    ? 'Next: wait for family’s reply'
                                    : page.status === 'resolved'
                                      ? 'No action outstanding'
                                      : 'Next: read and respond'}
                            </span>
                        )}
                        <div className="ml-auto flex items-center gap-1">
                            {page.can_create_followup && (
                                <ChatFamilyFollowup
                                    compact={inbox}
                                    actorId={actorId}
                                    conversationId={conversationId}
                                />
                            )}
                            {inbox && (
                                <ChatCallbacks
                                    compact
                                    actorId={actorId}
                                    conversationId={conversationId}
                                    family={false}
                                />
                            )}
                        </div>
                    </div>
                )}
                {!mode && (error || draft.pending) && (
                    <div className="space-y-2 px-4 pb-3">{feedback}</div>
                )}
            </div>
            <Dialog
                open={mode !== null}
                onOpenChange={(value) => {
                    if (!value) setMode(null);
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0"
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-12">
                        <DialogTitle>
                            {mode === 'note'
                                ? 'Staff-only coordination'
                                : mode === 'handoff'
                                  ? 'Hand over family work'
                                  : mode === 'claim'
                                    ? 'Take ownership'
                                    : 'Manage ownership'}
                        </DialogTitle>
                        <DialogDescription>{clientName}</DialogDescription>
                    </DialogHeader>
                    <div
                        ref={historyArea}
                        className="min-h-0 space-y-4 overflow-y-auto p-5"
                    >
                        {feedback}
                        {page && (
                            <>
                                {mode === 'note' ? (
                                    <>
                                        <p className="flex gap-2 rounded-md border bg-muted/40 p-3 text-sm">
                                            <LockKeyhole className="size-4 shrink-0" />
                                            Only authorised care-team staff can
                                            see this panel. These notes do not
                                            appear in family chat.
                                        </p>
                                        <div className="space-y-3">
                                            {page.events.map((event) => (
                                                <article
                                                    key={event.id}
                                                    data-work-event={event.id}
                                                    className="rounded-md border p-3 text-sm"
                                                >
                                                    <p className="font-semibold">
                                                        {event.actor_name} ·{' '}
                                                        {formatDateTime(
                                                            event.created_at,
                                                        )}
                                                    </p>
                                                    {event.action !==
                                                        'note' && (
                                                        <p>
                                                            {event.action ===
                                                            'status'
                                                                ? familyWorkStatus[
                                                                      event
                                                                          .status
                                                                  ]
                                                                : `Ownership: ${event.owner_name || 'Team queue'}`}
                                                        </p>
                                                    )}
                                                    {event.note && (
                                                        <p className="mt-1 break-words whitespace-pre-wrap">
                                                            {event.note}
                                                        </p>
                                                    )}
                                                </article>
                                            ))}
                                            {!page.events.length && (
                                                <p className="text-sm text-muted-foreground">
                                                    No staff coordination notes
                                                    in your available history.
                                                </p>
                                            )}
                                            {page.next_before && (
                                                <Button
                                                    variant="outline"
                                                    className="min-h-[44px]"
                                                    disabled={
                                                        loading || writing
                                                    }
                                                    onClick={() => void older()}
                                                >
                                                    Older staff notes
                                                </Button>
                                            )}
                                        </div>
                                        <label className="block space-y-2 text-sm font-medium">
                                            Staff coordination note
                                            <textarea
                                                ref={noteInput}
                                                data-work-event="composer"
                                                className="min-h-28 w-full rounded-md border bg-background p-3 font-normal"
                                                maxLength={4000}
                                                value={draft.coordination}
                                                disabled={disabled}
                                                onChange={(event) =>
                                                    remember({
                                                        coordination:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </label>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-sm">
                                            Current owner:{' '}
                                            <strong>
                                                {page.owner_name ||
                                                    'Unassigned'}
                                            </strong>
                                        </p>
                                        {conflict && (
                                            <div
                                                role="status"
                                                className="space-y-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning"
                                            >
                                                <p>
                                                    {error?.code === 409
                                                        ? error.message
                                                        : 'Ownership or conversation details changed. Review the current owner before saving.'}{' '}
                                                    Your draft is kept.
                                                </p>
                                                <Button
                                                    variant="outline"
                                                    className="min-h-[44px]"
                                                    onClick={() => {
                                                        remember({
                                                            owner: String(
                                                                page.assigned_to ??
                                                                    actorId,
                                                            ),
                                                            version:
                                                                page.version,
                                                        });
                                                        if (!draft.pending)
                                                            setError(null);
                                                    }}
                                                >
                                                    Use current owner
                                                </Button>
                                            </div>
                                        )}
                                        <div className="block space-y-2 text-sm font-medium">
                                            <label htmlFor={`${fieldId}-owner`}>
                                                Next owner
                                            </label>
                                            <Select
                                                value={
                                                    draft.owner || TEAM_QUEUE
                                                }
                                                disabled={disabled}
                                                onValueChange={(owner) =>
                                                    remember({
                                                        owner:
                                                            owner === TEAM_QUEUE
                                                                ? ''
                                                                : owner,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`${fieldId}-owner`}
                                                    className="min-h-[44px] w-full bg-background font-normal"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem
                                                        value={TEAM_QUEUE}
                                                    >
                                                        Release to team queue
                                                    </SelectItem>
                                                    {draft.owner &&
                                                        !page.owners.some(
                                                            (person) =>
                                                                String(
                                                                    person.id,
                                                                ) ===
                                                                draft.owner,
                                                        ) && (
                                                            <SelectItem
                                                                value={
                                                                    draft.owner
                                                                }
                                                                disabled
                                                            >
                                                                No longer
                                                                available
                                                            </SelectItem>
                                                        )}
                                                    {page.owners.map(
                                                        (person) => (
                                                            <SelectItem
                                                                key={person.id}
                                                                value={String(
                                                                    person.id,
                                                                )}
                                                            >
                                                                {person.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <label className="block space-y-2 text-sm font-medium">
                                            Staff handover note
                                            <textarea
                                                className="min-h-28 w-full rounded-md border bg-background p-3 font-normal"
                                                placeholder="What is still needed?"
                                                maxLength={4000}
                                                value={draft.note}
                                                disabled={disabled}
                                                onChange={(event) =>
                                                    remember({
                                                        note: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </label>
                                        {mode === 'handoff' && (
                                            <p className="rounded-md border bg-muted/40 p-3 text-sm">
                                                Unresolved work stays with the
                                                team. You can finish and clock
                                                out without waiting for a reply.
                                                No off-duty response is
                                                expected.
                                            </p>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 border-t p-4">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={() => setMode(null)}
                        >
                            Close
                        </Button>
                        <Button
                            className="min-h-[44px]"
                            disabled={
                                disabled ||
                                (mode === 'note'
                                    ? !draft.coordination.trim()
                                    : !!conflict ||
                                      (!!draft.owner &&
                                          !page?.owners.some(
                                              (person) =>
                                                  String(person.id) ===
                                                  draft.owner,
                                          )))
                            }
                            onClick={() => {
                                const action =
                                    mode === 'claim' &&
                                    draft.owner === String(actorId)
                                        ? 'claim'
                                        : mode === 'claim'
                                          ? 'assign'
                                          : mode;
                                void submit(
                                    JSON.stringify({
                                        mutation_id: crypto.randomUUID(),
                                        action,
                                        ...(action === 'note'
                                            ? { note: draft.coordination }
                                            : {
                                                  version: draft.version,
                                                  note: draft.note,
                                                  ...(action === 'claim'
                                                      ? {}
                                                      : {
                                                            assigned_to:
                                                                draft.owner
                                                                    ? Number(
                                                                          draft.owner,
                                                                      )
                                                                    : null,
                                                        }),
                                              }),
                                    }),
                                );
                            }}
                        >
                            {mode === 'note'
                                ? 'Add staff note'
                                : mode === 'claim'
                                  ? 'Confirm owner'
                                  : 'Save handover'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

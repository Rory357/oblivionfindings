/* Centered preview modal for the All Tasks queue. Opens on row click, fetches
 * the permission-scoped detail payload (item + audit timeline + watchers +
 * canAssign/canSplit) from GET /tasks/detail, and carries the deep link that
 * used to live on the row itself. Uses the shared Dialog chrome so it matches
 * every other modal in the app. Assignment / watch / split mirror the queue's
 * write actions. */
import { JourneyTermHelp } from '@/components/journey-term-help';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { journeyActivityLabel } from '@/lib/journey-labels';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ExternalLink,
    Eye,
    GitBranchPlus,
    History,
    Loader2,
    Plus,
    UserCheck,
    Users,
    UserX,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';
import { JourneyReferenceStrip } from './journey-reference-strip';
import {
    childLabelFor,
    dueInfo,
    humanise,
    SEVERITY_VARIANT,
    taskNumericId,
    taskRecordSource,
    taskStateLabel,
    type NamedRef,
    type TaskDetail,
    type TaskItem,
} from './types';

function MetaRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <dt className="shrink-0 text-xs font-semibold text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0 text-right text-sm">{children}</dd>
        </div>
    );
}

/** Two-letter monogram for a watcher chip. */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
    return (first + last).toUpperCase() || '?';
}

type SplitPayload = {
    title: string;
    description: string | null;
    assignee_id: number | null;
    due_at: string | null;
};

/** Inline "split into a child task" form. Collapsed to a single button until
 *  opened. The assignee field is a debounced typeahead backed by GET
 *  /tasks/users — it fails soft (an empty list) if that endpoint 401s. */
function SplitTaskForm({
    childLabel,
    open,
    busy,
    onOpen,
    onCancel,
    onSubmit,
}: {
    childLabel: string;
    open: boolean;
    busy: boolean;
    onOpen: () => void;
    onCancel: () => void;
    onSubmit: (data: SplitPayload) => void;
}) {
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [dueAt, setDueAt] = useState('');
    const [assignee, setAssignee] = useState<NamedRef | null>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<NamedRef[]>([]);
    const [showResults, setShowResults] = useState(false);
    const searchSeq = useRef(0);

    // Reset the whole form whenever it collapses.
    useEffect(() => {
        if (!open) {
            setTitle('');
            setDescription('');
            setDueAt('');
            setAssignee(null);
            setQuery('');
            setResults([]);
            setShowResults(false);
        }
    }, [open]);

    // Debounced staff search. Only fires while the picker is open and no one
    // is selected yet, so we don't re-query after a pick.
    useEffect(() => {
        if (!open || assignee) return;
        const t = setTimeout(async () => {
            const mySeq = ++searchSeq.current;
            try {
                const qs = query ? `?q=${encodeURIComponent(query)}` : '';
                const res = await fetch(`/tasks/users${qs}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error(String(res.status));
                const data = (await res.json()) as { users: NamedRef[] };
                if (searchSeq.current === mySeq) setResults(data.users ?? []);
            } catch {
                // Fail soft — no picker options rather than a broken form.
                if (searchSeq.current === mySeq) setResults([]);
            }
        }, 250);
        return () => clearTimeout(t);
    }, [query, open, assignee]);

    if (!open) {
        return (
            <div className="mt-5">
                <Button variant="outline" size="sm" onClick={onOpen}>
                    <GitBranchPlus className="h-4 w-4" />
                    Add {childLabel}
                </Button>
            </div>
        );
    }

    const canSubmit = title.trim().length > 0 && !busy;
    const submit = () => {
        if (!canSubmit) return;
        onSubmit({
            title: title.trim(),
            description: description.trim() || null,
            assignee_id: assignee?.id ?? null,
            due_at: dueAt || null,
        });
    };

    return (
        <div className="mt-5 rounded-lg border border-border bg-muted/30 p-3">
            <h3 className="mb-3 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <GitBranchPlus className="h-3.5 w-3.5" />
                Add {childLabel}
            </h3>

            <div className="space-y-3">
                <div>
                    <label
                        className="mb-1 block text-xs font-medium text-muted-foreground"
                        htmlFor="split-title"
                    >
                        Title
                    </label>
                    <input
                        id="split-title"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        maxLength={200}
                        placeholder={`What needs doing for this ${childLabel}?`}
                        className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>

                <div>
                    <label
                        className="mb-1 block text-xs font-medium text-muted-foreground"
                        htmlFor="split-desc"
                    >
                        Description{' '}
                        <span className="font-normal">(optional)</span>
                    </label>
                    <textarea
                        id="split-desc"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        maxLength={2000}
                        rows={3}
                        className="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label
                            className="mb-1 block text-xs font-medium text-muted-foreground"
                            htmlFor="split-due"
                        >
                            Due date{' '}
                            <span className="font-normal">(optional)</span>
                        </label>
                        <input
                            id="split-due"
                            type="date"
                            value={dueAt}
                            onChange={(e) => setDueAt(e.target.value)}
                            className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>

                    <div className="relative">
                        <label
                            className="mb-1 block text-xs font-medium text-muted-foreground"
                            htmlFor="split-assignee"
                        >
                            Assignee{' '}
                            <span className="font-normal">(optional)</span>
                        </label>
                        {assignee ? (
                            <GuardrailCard
                                unstyled
                                className="flex h-9 items-center justify-between gap-2 rounded-lg border border-border bg-background px-3 text-sm"
                            >
                                <span className="truncate">
                                    {assignee.name}
                                </span>
                                <Button
                                    unstyled
                                    type="button"
                                    aria-label="Clear assignee"
                                    onClick={() => {
                                        setAssignee(null);
                                        setQuery('');
                                    }}
                                    className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    <UserX className="h-3.5 w-3.5" />
                                </Button>
                            </GuardrailCard>
                        ) : (
                            <input
                                id="split-assignee"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                onFocus={() => setShowResults(true)}
                                onBlur={() =>
                                    setTimeout(() => setShowResults(false), 150)
                                }
                                placeholder="Unassigned"
                                autoComplete="off"
                                className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary"
                            />
                        )}
                        {!assignee && showResults && results.length > 0 ? (
                            <ul className="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-border bg-popover py-1 shadow-md">
                                {results.map((u) => (
                                    <li key={u.id}>
                                        <button
                                            type="button"
                                            // onMouseDown beats the input's blur so the pick registers.
                                            onMouseDown={(e) => {
                                                e.preventDefault();
                                                setAssignee(u);
                                                setShowResults(false);
                                            }}
                                            className="w-full px-3 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                                        >
                                            {u.name}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 pt-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={onCancel}
                    >
                        Cancel
                    </Button>
                    <Button size="sm" disabled={!canSubmit} onClick={submit}>
                        {busy ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Plus className="h-4 w-4" />
                        )}
                        Create
                    </Button>
                </div>
            </div>
        </div>
    );
}

export function TaskDetailDialog({
    item,
    currentUserId,
    onClose,
    returnTo,
    triggerRef,
}: {
    item: TaskItem | null;
    currentUserId: number;
    onClose: () => void;
    returnTo: string;
    triggerRef: RefObject<HTMLElement | null>;
}) {
    const [detail, setDetail] = useState<TaskDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [assigning, setAssigning] = useState(false);
    const [watchBusy, setWatchBusy] = useState(false);
    // Split-a-child form (collapsed until the user opens it).
    const [splitOpen, setSplitOpen] = useState(false);
    const [splitBusy, setSplitBusy] = useState(false);
    // Guards against a slow response landing after the user opened another row.
    const seq = useRef(0);
    const titleRef = useRef<HTMLHeadingElement>(null);

    const fetchDetail = useCallback(
        async (target: TaskItem) => {
            const mySeq = ++seq.current;
            setLoading(true);
            setError(null);
            try {
                const params = new URLSearchParams({
                    source: taskRecordSource(target),
                    id: taskNumericId(target),
                    return_to: returnTo,
                });
                const res = await fetch(`/tasks/detail?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = (await res.json()) as TaskDetail;
                if (seq.current === mySeq) setDetail(data);
            } catch {
                if (seq.current === mySeq)
                    setError(
                        'Could not load this task. It may have been removed, or you may not have access.',
                    );
            } finally {
                if (seq.current === mySeq) setLoading(false);
            }
        },
        [returnTo],
    );

    useEffect(() => {
        if (!item) return;
        setDetail(null);
        setError(null);
        setSplitOpen(false);
        void fetchDetail(item);
    }, [item, fetchDetail]);

    // Prefer the freshly fetched item (it reflects assignment changes) over
    // the possibly stale row the dialog was opened from.
    const display = detail?.item ?? item;

    const assign = (assigneeId: number | null) => {
        if (!item) return;
        setAssigning(true);
        router.post(
            `/tasks/${taskRecordSource(item)}/${taskNumericId(item)}/assign`,
            { assignee_id: assigneeId },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => void fetchDetail(item),
                onFinish: () => setAssigning(false),
            },
        );
    };

    // Follow / unfollow — refetch so the watcher list + button reflect it, and
    // the list page's Following count/stat pick the change up on reload.
    const toggleWatch = () => {
        if (!item) return;
        setWatchBusy(true);
        router.post(
            `/tasks/${taskRecordSource(item)}/${taskNumericId(item)}/watch`,
            {
                watching: !detail?.isWatching,
                return_to: returnTo,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => void fetchDetail(item),
                onFinish: () => setWatchBusy(false),
            },
        );
    };

    // Split into a child task → refetch (the new child shows in the timeline)
    // and let Inertia's own reload refresh the queue. Fails soft: the backend
    // returns back()->with('error', …) on any rule/permission rejection.
    const submitSplit = (data: SplitPayload) => {
        if (!item) return;
        setSplitBusy(true);
        router.post(
            `/tasks/${taskRecordSource(item)}/${taskNumericId(item)}/split`,
            data,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSplitOpen(false);
                    void fetchDetail(item);
                },
                onFinish: () => setSplitBusy(false),
            },
        );
    };

    const due = display ? dueInfo(display) : null;
    const assignedToMe = display?.assignee?.id === currentUserId;
    const childLabel = item ? childLabelFor(item.source) : 'follow-up';
    const canOpen = detail?.canOpen ?? Boolean(display?.link);

    return (
        <Dialog
            open={item !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                data-test="tasks-detail-dialog"
                className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    titleRef.current?.focus();
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    requestAnimationFrame(() => triggerRef.current?.focus());
                }}
            >
                {display ? (
                    <>
                        <DialogHeader className="border-b border-border p-5 pr-12 text-left">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {display.ref ? (
                                    <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground">
                                        {display.ref}
                                    </span>
                                ) : null}
                                <StatusBadge
                                    variant={SEVERITY_VARIANT[display.severity]}
                                    size="sm"
                                >
                                    {humanise(display.severity)}
                                </StatusBadge>
                                <StatusBadge variant="neutral" size="sm">
                                    {taskStateLabel(display)}
                                </StatusBadge>
                                <JourneyTermHelp
                                    terms={['severity', 'status']}
                                    label="Explain task status terms"
                                />
                            </div>
                            <DialogTitle
                                ref={titleRef}
                                tabIndex={-1}
                                className="text-base leading-snug outline-none"
                            >
                                {display.title}
                            </DialogTitle>
                            <DialogDescription>
                                {display.type ? `${display.type} · ` : ''}
                                {display.sourceLabel}
                                {display.sourceContext
                                    ? ` · ${humanise(display.sourceContext)}`
                                    : ''}
                            </DialogDescription>
                            <JourneyReferenceStrip journey={display.journey} />
                        </DialogHeader>

                        <div className="flex-1 overflow-y-auto px-5 py-4">
                            {display.description ? (
                                <p className="mb-4 text-sm whitespace-pre-line text-muted-foreground">
                                    {display.description}
                                </p>
                            ) : null}
                            {display.actionHelp ? (
                                <p className="mb-4 rounded-lg border border-status-info/30 bg-status-info-bg px-3 py-2 text-sm text-foreground">
                                    {display.actionHelp}
                                </p>
                            ) : null}

                            <dl className="divide-y divide-border rounded-lg border border-border px-3 py-1">
                                <MetaRow label="Assignee">
                                    {display.assignee ? (
                                        display.assignee.name
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Unassigned
                                        </span>
                                    )}
                                </MetaRow>
                                <MetaRow label="Client">
                                    <span className="text-muted-foreground">
                                        {display.client?.name ?? '—'}
                                    </span>
                                </MetaRow>
                                <MetaRow label="Site">
                                    <span className="text-muted-foreground">
                                        {display.site?.name ?? '—'}
                                    </span>
                                </MetaRow>
                                <MetaRow label="Due">
                                    <span className={due?.className}>
                                        {due?.label ?? '—'}
                                    </span>
                                </MetaRow>
                                <MetaRow label="Created">
                                    <span className="text-muted-foreground">
                                        {display.createdAt
                                            ? formatDateTime(display.createdAt)
                                            : '—'}
                                    </span>
                                </MetaRow>
                            </dl>

                            {/* ── Watchers ── */}
                            <div className="mt-5">
                                <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <Users className="h-3.5 w-3.5" />
                                    Watchers
                                </h3>
                                {detail && detail.watchers.length > 0 ? (
                                    <ul className="flex flex-wrap gap-1.5">
                                        {detail.watchers.map((w) => (
                                            <li
                                                key={w.id}
                                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-2 py-0.5 text-xs font-medium"
                                            >
                                                <span
                                                    aria-hidden
                                                    className="grid h-4 w-4 place-items-center rounded-full bg-primary/15 text-[9px] font-bold text-primary"
                                                >
                                                    {initials(w.name)}
                                                </span>
                                                {w.name}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {loading
                                            ? 'Loading…'
                                            : detail?.watchersHidden
                                              ? 'Follower list hidden on this restricted record.'
                                              : 'No watchers yet.'}
                                    </p>
                                )}
                            </div>

                            {/* ── Split into a child task ── */}
                            {detail?.canSplit ? (
                                <SplitTaskForm
                                    key={item?.id}
                                    childLabel={childLabel}
                                    open={splitOpen}
                                    busy={splitBusy}
                                    onOpen={() => setSplitOpen(true)}
                                    onCancel={() => setSplitOpen(false)}
                                    onSubmit={submitSplit}
                                />
                            ) : null}

                            {/* ── Activity timeline ── */}
                            <div className="mt-5">
                                <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <History className="h-3.5 w-3.5" />
                                    Activity
                                </h3>
                                {loading ? (
                                    <div className="flex items-center gap-2 py-3 text-sm text-muted-foreground">
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Loading activity…
                                    </div>
                                ) : error ? (
                                    <div className="flex flex-col items-start gap-2 rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-2">
                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                            {error}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                item && void fetchDetail(item)
                                            }
                                        >
                                            Retry
                                        </Button>
                                    </div>
                                ) : detail && detail.timeline.length > 0 ? (
                                    <ul className="space-y-0">
                                        {detail.timeline.map((entry, i) => (
                                            <li
                                                key={entry.id}
                                                className="relative flex gap-3 pb-3 last:pb-0"
                                            >
                                                {/* Rail connecting the dots (skipped after the last entry). */}
                                                {i <
                                                detail.timeline.length - 1 ? (
                                                    <span
                                                        className="absolute top-3 left-[3.5px] h-full w-px bg-border"
                                                        aria-hidden
                                                    />
                                                ) : null}
                                                <span
                                                    className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary/60"
                                                    aria-hidden
                                                />
                                                <div className="min-w-0 text-sm">
                                                    <div className="font-medium">
                                                        {journeyActivityLabel(
                                                            entry.action,
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {entry.user ?? 'System'}
                                                        {entry.at
                                                            ? ` · ${formatDateTime(entry.at)}`
                                                            : ''}
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="py-1 text-sm text-muted-foreground">
                                        No recorded activity yet.
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* ── Footer actions ── */}
                        <div className="flex flex-wrap items-center gap-2 border-t border-border p-4">
                            {canOpen && display.link ? (
                                <Button
                                    className="flex-1"
                                    onClick={() => router.visit(display.link!)}
                                >
                                    <ExternalLink className="h-4 w-4" />
                                    {display.actionLabel ?? 'Open record'}
                                </Button>
                            ) : null}
                            {detail?.canWatch ? (
                                <Button
                                    variant={
                                        detail.isWatching
                                            ? 'default'
                                            : 'outline'
                                    }
                                    disabled={watchBusy}
                                    onClick={toggleWatch}
                                    aria-pressed={detail.isWatching}
                                    title={
                                        detail.isWatching
                                            ? 'Stop following this task'
                                            : 'Follow this task'
                                    }
                                >
                                    {watchBusy ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Eye className="h-4 w-4" />
                                    )}
                                    {detail.isWatching ? 'Watching' : 'Watch'}
                                </Button>
                            ) : null}
                            {detail?.canAssign ? (
                                assignedToMe ? (
                                    <Button
                                        variant="outline"
                                        disabled={assigning}
                                        onClick={() => assign(null)}
                                    >
                                        <UserX className="h-4 w-4" />
                                        Unassign
                                    </Button>
                                ) : (
                                    <>
                                        <Button
                                            variant="outline"
                                            disabled={assigning}
                                            onClick={() =>
                                                assign(currentUserId)
                                            }
                                        >
                                            <UserCheck className="h-4 w-4" />
                                            Assign to me
                                        </Button>
                                        {display.assignee ? (
                                            <Button
                                                variant="outline"
                                                disabled={assigning}
                                                onClick={() => assign(null)}
                                            >
                                                <UserX className="h-4 w-4" />
                                                Unassign
                                            </Button>
                                        ) : null}
                                    </>
                                )
                            ) : null}
                        </div>
                    </>
                ) : (
                    // Radix requires a title while mounted; display is only null on close.
                    <DialogTitle className="sr-only">Task detail</DialogTitle>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default TaskDetailDialog;

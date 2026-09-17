import { ConfirmDialog } from '@/components/confirm-dialog';
import { TicketDraftRecovery } from '@/components/it/ticket-draft-recovery';
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
import { Textarea } from '@/components/ui/textarea';
import {
    draftSnapshotKey,
    type ItDraftCommitReference,
    type ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import { formatDateTime, formatDurationMinutes } from '@/lib/datetime';
import axios from 'axios';
import { CalendarDays, Clock3, Pencil, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    TicketWorkPeriodFields,
    TicketWorkPersonPicker,
    WorkField,
    WorkSelect,
    workError,
} from './ticket-work-fields';
import {
    workLocal,
    type TicketWork,
    type WorkBooking,
    type WorkCost,
    type WorkEntry,
    type WorkNote,
    type WorkPerson,
} from './ticket-work-types';

const money = (cents: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(cents / 100);
const words = (value: string) => value.replaceAll('_', ' ');
/** Match Laravel's trimmed strings and empty-to-null request values before freezing a draft command. */
function workCommandPayload(
    payload: Record<string, unknown>,
): Record<string, unknown> {
    const normalize = (value: unknown): unknown => {
        if (typeof value === 'string') return value.trim() || null;
        if (Array.isArray(value)) return value.map(normalize);
        if (value && typeof value === 'object')
            return Object.fromEntries(
                Object.entries(value).map(([key, child]) => [
                    key,
                    normalize(child),
                ]),
            );
        return value;
    };
    return normalize(payload) as Record<string, unknown>;
}
type Proposal = {
    operation: string;
    title: string;
    payload: Record<string, unknown>;
};
type Command = Partial<ItDraftCommitReference> & {
    actor_user_id: number;
    expected_version: number;
    request_uuid: string;
    operation: string;
    payload: Record<string, unknown>;
};

export function TicketWorkContext({
    work,
    onDetails,
}: {
    work: TicketWork;
    onDetails: () => void;
}) {
    const follow = work.details?.follow_up as WorkNote['follow_up'];
    const contact = (title: string, person?: WorkPerson | null) => (
        <div>
            <p className="text-xs font-semibold text-muted-foreground">
                {title}
            </p>
            <p className="font-medium">{person?.name ?? 'Not recorded'}</p>
            {person?.email && (
                <a
                    className="frontline-focus block text-sm break-all text-primary underline"
                    href={`mailto:${person.email}`}
                >
                    {person.email}
                </a>
            )}
            {person?.work_phone && (
                <a
                    className="frontline-focus block text-sm text-primary underline"
                    href={`tel:${person.work_phone.replace(/[^+\d]/g, '')}`}
                >
                    {person.work_phone}
                </a>
            )}
        </div>
    );
    return (
        <section
            className="space-y-4 rounded-xl border border-border bg-card p-4"
            aria-label="People and next action"
        >
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold">People & contact</h3>
                <Button size="sm" variant="ghost" onClick={onDetails}>
                    Details
                </Button>
            </div>
            {contact('Requester', work.requester)}
            {contact('Affected user', work.affected_user)}
            {work.alternate_contact &&
                contact('Alternate contact', work.alternate_contact)}
            {work.details?.preferred_channel && (
                <p className="text-sm">
                    Preferred contact: {String(work.details.preferred_channel)}
                </p>
            )}
            {work.details?.callback_window && (
                <p className="text-sm">
                    Contact window: {String(work.details.callback_window)}
                </p>
            )}
            {follow && (
                <div className="space-y-1 border-t border-border pt-3">
                    <p className="text-xs font-semibold">Next action</p>
                    <p className="text-sm">{follow.action}</p>
                    <p className="text-xs">
                        {work.follow_up_owner} · {formatDateTime(follow.due_at)}
                    </p>
                    {new Date(follow.due_at).getTime() < Date.now() && (
                        <p className="text-sm font-semibold text-status-warning">
                            Follow-up overdue
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

export function TicketDiagnosticSummary({
    work,
    onDetails,
}: {
    work: TicketWork;
    onDetails: () => void;
}) {
    const details = work.details ?? {};
    return (
        <details className="rounded-xl border border-border bg-card p-4">
            <summary className="frontline-focus cursor-pointer text-sm font-semibold">
                Diagnostic summary ·{' '}
                {details.affected_count
                    ? `${details.affected_count} affected`
                    : 'Impact and troubleshooting'}
            </summary>
            <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                {[
                    ['Location', 'location'],
                    ['First noticed', 'onset'],
                    ['Impact', 'impact_summary'],
                    ['Workaround', 'workaround'],
                    ['Access instructions', 'access_instructions'],
                    ['Already tried', 'already_tried'],
                    ['Vendor reference', 'vendor_reference'],
                    ['Support reference', 'support_reference'],
                ].map(([label, key]) => (
                    <div key={key}>
                        <dt className="font-medium">{label}</dt>
                        <dd className="whitespace-pre-wrap text-muted-foreground">
                            {String(details[key] || 'Not recorded')}
                        </dd>
                    </div>
                ))}
            </dl>
            <Button variant="ghost" className="mt-3" onClick={onDetails}>
                Edit ticket context
            </Button>
        </details>
    );
}

/** A pending save retains the exact UUID and values; changing a proposal requires a definitive rejection. */
export function TicketWorkWorkspace({
    draftsEnabled = false,
    work,
    ticketId,
    actorId,
    version,
    tab,
    editable,
    onRefresh,
    onNote,
    notes = [],
    onDirtyChange,
}: {
    work: TicketWork;
    ticketId: number;
    draftsEnabled?: boolean;
    actorId: number;
    version: number;
    tab: string;
    editable: boolean;
    onRefresh: () => void;
    onNote: (commentId?: number) => void;
    notes?: {
        id: number;
        body: string;
        is_internal: boolean;
        author: { id: number | null; name: string };
    }[];
    onDirtyChange?: (state: { dirty: boolean; busy: boolean }) => void;
}) {
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [pending, setPending] = useState<Command | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [stale, setStale] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [hidden, setHidden] = useState(false);
    const [undo, setUndo] = useState<WorkBooking | null>(null);
    const [timeFilter, setTimeFilter] = useState('all');
    const [selectedTechs, setSelectedTechs] = useState<WorkPerson[]>([]);
    const submitting = useRef(false);
    const [baseVersion, setBaseVersion] = useState(version);
    const [recoveryPending, setRecoveryPending] = useState(false);
    const [recoveryEpoch, setRecoveryEpoch] = useState(0);
    const recoveryKey = `it-work-command:${actorId}:${ticketId}`;
    const snapshot = useMemo<ItDraftSnapshot>(
        () => ({
            fields: proposal
                ? {
                      work_form: JSON.stringify({
                          proposal,
                          pending: pending
                              ? {
                                    actor_user_id: pending.actor_user_id,
                                    expected_version: pending.expected_version,
                                    request_uuid: pending.request_uuid,
                                    operation: pending.operation,
                                    payload: pending.payload,
                                }
                              : null,
                      }),
                  }
                : {},
            step_index: 0,
            base_ticket_version: baseVersion,
        }),
        [proposal, pending, baseVersion],
    );
    const draft = useItTicketDraft({
        enabled: draftsEnabled,
        actorId,
        context: { purpose: 'ticket_work', ticketId },
        workingSnapshot: snapshot,
        workingDirty: !!proposal,
        acceptedFields: ['work_form'],
        onAccessLost: () => {
            setHidden(true);
            setProposal(null);
            setPending(null);
            onRefresh();
        },
    });
    const draftBlocked =
        draftsEnabled && !['ready', 'idle'].includes(draft.state);
    const failedDraft = useRef<string | null>(null);
    const snapshotKey = draftSnapshotKey(snapshot);
    useEffect(() => {
        if (
            !draftsEnabled ||
            !proposal ||
            pending ||
            busy ||
            draft.busy ||
            draft.state !== 'ready' ||
            draft.isSaved(snapshot) ||
            failedDraft.current === snapshotKey
        )
            return;
        const timeout = setTimeout(async () => {
            if (!(await draft.save(snapshot)))
                failedDraft.current = snapshotKey;
        }, 750);
        return () => clearTimeout(timeout);
    }, [draftsEnabled, proposal, pending, busy, draft, snapshot, snapshotKey]);
    useEffect(() => {
        let live = true;
        let stored: { operation: string; request_uuid: string } | null = null;
        try {
            stored = JSON.parse(sessionStorage.getItem(recoveryKey) ?? 'null');
        } catch {
            /* Browser storage is optional. */
        }
        if (
            !stored?.request_uuid ||
            !/^[a-f0-9-]{36}$/i.test(stored.request_uuid)
        )
            return;
        setRecoveryPending(true);
        void axios
            .get(
                `/it/tickets/${ticketId}/work/commands/${stored.request_uuid}`,
                { params: { operation: stored.operation } },
            )
            .then((response) => {
                if (
                    !live ||
                    !['committed', 'cancelled'].includes(response.data.status)
                )
                    return;
                try {
                    sessionStorage.removeItem(recoveryKey);
                } catch {
                    /* Optional opaque recovery. */
                }
                setRecoveryPending(false);
                setError(
                    response.data.status === 'committed'
                        ? 'Your earlier work save was confirmed. The saved ticket has been refreshed.'
                        : 'Your earlier request was cancelled. Discard its saved draft before starting replacement work.',
                );
                onRefresh();
            })
            .catch(() => {
                if (live)
                    setError(
                        'An earlier save is unconfirmed. Resume the saved work draft and retry the same save before entering replacement work.',
                    );
            });
        return () => {
            live = false;
        };
        // Account and ticket identity bind the opaque recovery marker.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recoveryKey, ticketId, recoveryEpoch]);
    const restore = (saved: ItDraftSnapshot) => {
        try {
            const form = JSON.parse(saved.fields.work_form ?? '{}');
            if (!form.proposal?.operation || !form.proposal?.payload)
                throw new Error('This saved draft has no work form.');
            if (form.pending && form.pending.actor_user_id !== actorId)
                throw new Error(
                    'Recover this work using the original account.',
                );
            setProposal(form.proposal);
            setPending(form.pending ?? null);
            setBaseVersion(saved.base_ticket_version ?? version);
            setError('');
            setSelectedTechs(
                (form.proposal.payload.technician_user_ids ?? []).map(
                    (id: number) => ({
                        id,
                        name:
                            (work.bookings ?? []).find(
                                (b) => b.technician_user_id === id,
                            )?.technician_name ?? `Technician #${id}`,
                    }),
                ),
            );
        } catch (problem) {
            setError(workError(problem));
        }
    };
    useEffect(() => {
        onDirtyChange?.({ dirty: !!proposal, busy });
    }, [proposal, busy, onDirtyChange]);
    const open = async (next: Proposal) => {
        if (submitting.current || busy) return;
        if (
            !editable ||
            recoveryPending ||
            (draftsEnabled &&
                [
                    'available',
                    'checking',
                    'conflict',
                    'reviewing',
                    'outcome_unknown',
                ].includes(draft.state))
        ) {
            setError(
                'Resume or discard the saved work draft before starting a new form.',
            );
            return;
        }
        if (draftsEnabled && draft.state === 'terminal') {
            submitting.current = true;
            setBusy(true);
            try {
                // A commit acknowledgement intentionally has no fresh capabilities.
                const checked = await draft.check();
                if (
                    !checked ||
                    (checked.state === 'active' && checked.has_content)
                ) {
                    setError(
                        'Check and resume any saved work before starting a new form.',
                    );
                    return;
                }
                if (checked.state !== 'active' && !(await draft.startNew())) {
                    setError(
                        'The next work draft could not be started. Check the saved draft and try again.',
                    );
                    return;
                }
            } finally {
                submitting.current = false;
                setBusy(false);
            }
        }
        setProposal(next);
        setPending(null);
        setError('');
        setStale(false);
        setSelectedTechs([]);
        setBaseVersion(version);
    };
    const change = (key: string, value: unknown) =>
        setProposal((current) =>
            current
                ? { ...current, payload: { ...current.payload, [key]: value } }
                : null,
        );
    const str = (key: string) => String(proposal?.payload[key] ?? '');
    const num = (key: string) => Number(proposal?.payload[key] ?? 0);
    const text = (key: string, label: string, long = false) => (
        <WorkField label={label}>
            {long ? (
                <Textarea
                    value={str(key)}
                    maxLength={2000}
                    onChange={(e) => change(key, e.target.value)}
                />
            ) : (
                <Input
                    value={str(key)}
                    onChange={(e) => change(key, e.target.value)}
                />
            )}
        </WorkField>
    );
    const reason = () => text('reason', 'Reason for this change', true);
    const save = async () => {
        if (!proposal || submitting.current || hidden) return;
        let command: Command = pending ?? {
            actor_user_id: actorId,
            expected_version: baseVersion,
            request_uuid: crypto.randomUUID(),
            operation: proposal.operation,
            payload: workCommandPayload(proposal.payload),
        };
        submitting.current = true;
        setBusy(true);
        setError('');
        setPending(command);
        try {
            const commitSnapshot: ItDraftSnapshot = {
                fields: {
                    work_form: JSON.stringify({
                        proposal,
                        pending: {
                            actor_user_id: command.actor_user_id,
                            expected_version: command.expected_version,
                            request_uuid: command.request_uuid,
                            operation: command.operation,
                            payload: command.payload,
                        },
                    }),
                },
                step_index: 0,
                base_ticket_version: command.expected_version,
            };
            if (draftsEnabled && !command.draft_uuid) {
                if (
                    !draft.isSaved(commitSnapshot) &&
                    !(await draft.save(commitSnapshot))
                ) {
                    setPending(null);
                    return;
                }
                const reference = draft.submissionReference(commitSnapshot);
                if (!reference) {
                    setError(
                        'Save or review the work draft before submitting.',
                    );
                    setPending(null);
                    return;
                }
                command = { ...command, ...reference };
                setPending(command);
            }
            try {
                sessionStorage.setItem(
                    recoveryKey,
                    JSON.stringify({
                        operation: command.operation,
                        request_uuid: command.request_uuid,
                    }),
                );
            } catch {
                /* Never persist private fields to browser storage. */
            }
            const response = await axios.post(
                `/it/tickets/${ticketId}/work`,
                command,
                { timeout: 30000, headers: { Accept: 'application/json' } },
            );
            if (
                response.data.status === 'cancelled' &&
                response.data.request_uuid === command.request_uuid
            ) {
                setRecoveryPending(false);
                setPending(null);
                try {
                    sessionStorage.removeItem(recoveryKey);
                } catch {
                    /* Optional opaque recovery. */
                }
                setError(
                    'This request was cancelled and has not been saved. Review the retained fields before saving as a new request.',
                );
                return;
            }
            if (
                response.data.status !== 'committed' ||
                response.data.request_uuid !== command.request_uuid
            )
                throw new Error(
                    'Save could not be confirmed. Retry the same save to check its outcome.',
                );
            if (
                proposal.operation === 'booking' &&
                ['cancelled', 'reschedule'].includes(
                    String(proposal.payload.action),
                )
            )
                setUndo(
                    (work.bookings ?? []).find(
                        (b) => b.id === proposal.payload.id,
                    ) ?? null,
                );
            setProposal(null);
            setPending(null);
            if (response.data.draft)
                draft.acknowledgeConsumed(response.data.draft, commitSnapshot);
            draft.clearOwnedBrowserWork();
            setRecoveryPending(false);
            try {
                sessionStorage.removeItem(recoveryKey);
            } catch {
                /* Optional opaque recovery. */
            }
            onRefresh();
            toast.success('Ticket work saved.');
        } catch (problem) {
            if (
                axios.isAxiosError(problem) &&
                [401, 403, 404, 419].includes(problem.response?.status ?? 0)
            ) {
                setHidden(true);
                setProposal(null);
                setPending(null);
                onRefresh();
            } else {
                setError(workError(problem));
                if (
                    axios.isAxiosError(problem) &&
                    problem.response?.status === 422
                ) {
                    setPending(null);
                    setRecoveryPending(false);
                    try {
                        sessionStorage.removeItem(recoveryKey);
                    } catch {
                        /* Optional opaque recovery. */
                    }
                }
                if (
                    axios.isAxiosError(problem) &&
                    problem.response?.status === 409
                ) {
                    setStale(true);
                    setRecoveryPending(false);
                    try {
                        sessionStorage.removeItem(recoveryKey);
                    } catch {
                        /* Optional opaque recovery. */
                    }
                    onRefresh();
                }
            }
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    const discardWork = async () => {
        if (submitting.current) return;
        submitting.current = true;
        setBusy(true);
        try {
            let command = pending;
            if (!command && recoveryPending) {
                const stored = JSON.parse(
                    sessionStorage.getItem(recoveryKey) ?? 'null',
                );
                if (!stored?.request_uuid)
                    throw new Error(
                        'Check the earlier save before discarding its draft.',
                    );
                command = stored;
            }
            if (command) {
                const response = await axios.post(
                    `/it/tickets/${ticketId}/work/commands/${command.request_uuid}/cancel`,
                    { operation: command.operation },
                );
                if (
                    !['committed', 'cancelled'].includes(
                        response.data.status,
                    ) ||
                    response.data.request_uuid !== command.request_uuid
                )
                    throw new Error(
                        'Cancellation could not be confirmed. Check the earlier save before trying again.',
                    );
                setRecoveryPending(false);
                setPending(null);
                try {
                    sessionStorage.removeItem(recoveryKey);
                } catch {
                    /* Optional opaque recovery. */
                }
                if (response.data.status === 'committed') {
                    setProposal(null);
                    draft.clearOwnedBrowserWork();
                    setError(
                        'The earlier request had already saved. The saved ticket has been refreshed.',
                    );
                    onRefresh();
                    return;
                }
            }
            if (
                draftsEnabled &&
                !['idle', 'disabled', 'terminal'].includes(draft.state) &&
                !(await draft.discard())
            )
                return;
            setProposal(null);
            draft.clearOwnedBrowserWork();
            setError('Entered changes discarded.');
        } catch (problem) {
            setError(workError(problem));
        } finally {
            setDiscard(false);
            submitting.current = false;
            setBusy(false);
        }
    };
    const bookingAction = (booking: WorkBooking, action: string) =>
        open({
            operation: 'booking',
            title: `${words(action)} booking`,
            payload: {
                id: booking.id,
                action,
                starts_at: booking.starts_at,
                ends_at: booking.ends_at,
                reason: '',
            },
        });
    const review = (
        type: string,
        item: WorkEntry | WorkCost,
        decision: string,
    ) =>
        open({
            operation: 'review',
            title:
                decision === 'request_correction'
                    ? 'Request a correction'
                    : 'Review recorded work',
            payload: { type, id: item.id, decision, reason: '' },
        });
    const reviewButtons = (type: string, item: WorkEntry | WorkCost) => (
        <div className="flex flex-wrap gap-2">
            {item.approver_user_id === actorId &&
                ['pending', 'approved'].includes(item.approval_status) && (
                    <>
                        {item.approval_status === 'pending' && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => review(type, item, 'approved')}
                            >
                                Approve
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                review(type, item, 'changes_requested')
                            }
                        >
                            Return for changes
                        </Button>
                    </>
                )}
            {item.approval_status === 'approved' &&
                (item.recorded_by === actorId ||
                    ('technician_user_id' in item &&
                        item.technician_user_id === actorId)) && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => review(type, item, 'request_correction')}
                    >
                        Request correction
                    </Button>
                )}
        </div>
    );
    const recoveryControls =
        recoveryPending && !proposal ? (
            <Card className="flex flex-wrap gap-2 p-3">
                {draftsEnabled && (
                    <Button
                        variant="outline"
                        onClick={async () => {
                            const saved = await draft.resume();
                            if (saved) restore(saved.payload);
                        }}
                    >
                        Resume the earlier work form
                    </Button>
                )}
                <Button
                    variant="outline"
                    onClick={() => setRecoveryEpoch((epoch) => epoch + 1)}
                >
                    Check the earlier save
                </Button>
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => setDiscard(true)}
                >
                    Cancel the earlier request
                </Button>
            </Card>
        ) : pending ? null : (
            <TicketDraftRecovery
                draft={draft}
                snapshot={snapshot}
                hasLocalChanges={!!proposal}
                onResume={(saved) => restore(saved.payload)}
                onResumeMemory={(saved) => restore(saved.snapshot)}
                onDiscarded={() => {
                    setProposal(null);
                    setPending(null);
                    draft.clearOwnedBrowserWork();
                }}
                onStartNew={() => {
                    setProposal(null);
                    setPending(null);
                }}
                renderReview={(saved) => {
                    try {
                        const form = JSON.parse(
                            saved.payload.fields.work_form ?? '{}',
                        );
                        return (
                            <div className="space-y-2">
                                <p className="font-medium">
                                    {form.proposal?.title ??
                                        'Ticket work draft'}
                                </p>
                                <RevisionValues
                                    value={form.proposal?.payload}
                                />
                            </div>
                        );
                    } catch {
                        return <p>Ticket work draft could not be displayed.</p>;
                    }
                }}
            />
        );
    if (hidden)
        return (
            <p role="alert">
                Your access changed. Refresh the ticket to continue.
            </p>
        );
    if (!work.ready)
        return (
            <p role="status">
                Time, bookings and costs will be available when ticket work
                setup is complete.
            </p>
        );
    const entries = work.entries ?? [],
        bookings = work.bookings ?? [],
        costs = work.costs ?? [];
    return (
        <>
            {error && !proposal && (
                <p
                    role="status"
                    className="rounded-lg border border-border bg-card p-3 text-sm"
                >
                    {error}
                </p>
            )}
            {!proposal && recoveryControls}
            <section
                hidden={tab !== 'time'}
                className="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <div className="flex flex-wrap justify-between gap-3">
                    <div>
                        <h2 className="text-section-title">Time entries</h2>
                        <p className="text-sm text-muted-foreground">
                            {formatDurationMinutes(work.totals?.minutes ?? 0)}{' '}
                            actual ·{' '}
                            {formatDurationMinutes(
                                work.totals?.after_hours_minutes ?? 0,
                            )}{' '}
                            after hours
                        </p>
                    </div>
                    {editable && (
                        <Button onClick={() => onNote()}>
                            <Clock3 className="size-4" />
                            Add time with a note
                        </Button>
                    )}
                </div>
                <WorkSelect
                    label="Show time"
                    value={timeFilter}
                    onChange={setTimeFilter}
                    options={[
                        ['all', 'All time'],
                        ['after_hours', 'After hours'],
                        ['travel', 'Travel'],
                        ['mine', 'My time'],
                    ]}
                />
                <ul className="space-y-3">
                    {entries
                        .filter(
                            (entry) =>
                                timeFilter === 'all' ||
                                (timeFilter === 'after_hours' &&
                                    entry.after_hours) ||
                                (timeFilter === 'travel' &&
                                    entry.work_type === 'travel') ||
                                (timeFilter === 'mine' &&
                                    entry.technician_user_id === actorId),
                        )
                        .map((entry) => (
                            <li
                                key={entry.id}
                                className="space-y-2 rounded-xl border border-border p-4"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <p className="font-semibold">
                                        {entry.technician_name} ·{' '}
                                        {formatDurationMinutes(entry.minutes)}
                                    </p>
                                    <span className="text-sm">
                                        {entry.after_hours
                                            ? 'After hours'
                                            : 'Standard hours'}{' '}
                                        · {words(entry.work_type)}
                                    </span>
                                </div>
                                <p className="text-sm">
                                    {formatDateTime(entry.starts_at)} →{' '}
                                    {formatDateTime(entry.ends_at)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {entry.break_minutes} min breaks · Recorded
                                    by {entry.recorded_by_name} ·{' '}
                                    {words(entry.approval_status)}
                                    {entry.approver_name
                                        ? ` · Reviewer: ${entry.approver_name}`
                                        : ''}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => onNote(entry.comment_id)}
                                    >
                                        Open linked note #{entry.comment_id}
                                    </Button>
                                    {editable &&
                                        entry.approval_status !== 'approved' &&
                                        (entry.recorded_by === actorId ||
                                            entry.technician_user_id ===
                                                actorId) && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    open({
                                                        operation:
                                                            'correct_time',
                                                        title: 'Correct time entry',
                                                        payload: {
                                                            ...entry,
                                                            reason: '',
                                                        },
                                                    })
                                                }
                                            >
                                                <Pencil className="size-4" />
                                                Correct time
                                            </Button>
                                        )}
                                    {editable && reviewButtons('time', entry)}
                                </div>
                            </li>
                        ))}
                </ul>
                {!entries.length && (
                    <p className="text-sm text-muted-foreground">
                        No time recorded yet. Add the actual start and end time
                        while writing a work note.
                    </p>
                )}
            </section>
            <section
                hidden={tab !== 'schedule'}
                className="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <div className="flex flex-wrap justify-between gap-3">
                    <div>
                        <h2 className="text-section-title">
                            Technician schedule
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Planned visits appear in each technician’s My
                            Calendar. Actual time is recorded with a note.
                        </p>
                    </div>
                    {editable && (
                        <Button
                            onClick={() =>
                                open({
                                    operation: 'book',
                                    title: 'Schedule additional technicians',
                                    payload: {
                                        starts_at: '',
                                        ends_at: '',
                                        technician_user_ids: [],
                                        brief: '',
                                        location: '',
                                    },
                                })
                            }
                        >
                            <CalendarDays className="size-4" />
                            Schedule technicians
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    Availability checks local ticket bookings, leave, time off,
                    published shifts and personal/site calendars. Check any
                    unsynchronised external commitments with the technician.
                </p>
                {undo && editable && (
                    <div className="flex items-center justify-between rounded-lg border border-border p-3">
                        <span className="text-sm">
                            Booking changed. Undo will request the previous
                            period again and recheck availability.
                        </span>
                        <Button
                            variant="outline"
                            onClick={() => {
                                open({
                                    operation: 'booking',
                                    title: 'Undo booking change',
                                    payload: {
                                        id: undo.id,
                                        action:
                                            bookings.find(
                                                (b) => b.id === undo.id,
                                            )?.status === 'cancelled'
                                                ? 'restore'
                                                : 'reschedule',
                                        starts_at: undo.starts_at,
                                        ends_at: undo.ends_at,
                                        reason: 'Undo the last booking change',
                                    },
                                });
                                setUndo(null);
                            }}
                        >
                            Undo
                        </Button>
                    </div>
                )}
                <ul className="space-y-3">
                    {bookings.map((booking) => (
                        <li
                            className="space-y-2 rounded-xl border border-border p-4"
                            key={booking.id}
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <p className="font-semibold">
                                    {booking.technician_name}
                                </p>
                                <span className="text-sm capitalize">
                                    {booking.status}
                                </span>
                            </div>
                            <p className="text-sm">
                                {formatDateTime(booking.starts_at)} →{' '}
                                {formatDateTime(booking.ends_at)}
                            </p>
                            <p className="text-sm">{booking.details.brief}</p>
                            <p className="text-xs text-muted-foreground">
                                {booking.details.location ||
                                    'Location not specified'}
                            </p>
                            {editable && (
                                <div className="flex flex-wrap gap-2">
                                    {booking.status === 'requested' &&
                                        booking.technician_user_id ===
                                            actorId && (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        bookingAction(
                                                            booking,
                                                            'accepted',
                                                        )
                                                    }
                                                >
                                                    Accept
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        bookingAction(
                                                            booking,
                                                            'declined',
                                                        )
                                                    }
                                                >
                                                    Decline
                                                </Button>
                                            </>
                                        )}
                                    {['requested', 'accepted'].includes(
                                        booking.status,
                                    ) && (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    bookingAction(
                                                        booking,
                                                        'reschedule',
                                                    )
                                                }
                                            >
                                                Reschedule
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    bookingAction(
                                                        booking,
                                                        'cancelled',
                                                    )
                                                }
                                            >
                                                Cancel booking
                                            </Button>
                                        </>
                                    )}
                                    {booking.status === 'accepted' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => onNote()}
                                        >
                                            Complete with actual work note
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                {!bookings.length && (
                    <p className="text-sm text-muted-foreground">
                        No additional technician visits scheduled.
                    </p>
                )}
            </section>
            <section
                hidden={tab !== 'costs'}
                className="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <div className="flex flex-wrap justify-between gap-3">
                    <div>
                        <h2 className="text-section-title">
                            Parts, expenses & internal cost
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Parts and expenses{' '}
                            {money(work.totals?.cost_cents ?? 0)} · Priced
                            labour {money(work.totals?.labour_cents ?? 0)}
                        </p>
                    </div>
                    {editable && (
                        <Button
                            onClick={() =>
                                open({
                                    operation: 'cost',
                                    title: 'Record a part or expense',
                                    payload: {
                                        kind: 'part',
                                        incurred_on: workLocal().slice(0, 10),
                                        quantity_hundredths: 100,
                                        unit_cost_cents: 0,
                                        description: '',
                                        reference: '',
                                        require_approval: false,
                                    },
                                })
                            }
                        >
                            <Plus className="size-4" />
                            Add cost
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    Internal NZD estimates only. Rates are recorded when time is
                    saved.{' '}
                    {formatDurationMinutes(work.totals?.unpriced_minutes ?? 0)}{' '}
                    has no recorded rate. Manage ticket-specific rates and
                    review rules in People & diagnostics.
                </p>
                <ul className="space-y-3">
                    {costs.map((cost) => (
                        <li
                            key={cost.id}
                            className="space-y-2 rounded-xl border border-border p-4"
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <p className="font-semibold">
                                    {cost.details.description}
                                </p>
                                <p>{money(cost.total_cents)}</p>
                            </div>
                            <p className="text-sm">
                                {cost.quantity_hundredths / 100} ×{' '}
                                {money(cost.unit_cost_cents)} ·{' '}
                                {words(cost.kind)} · {cost.incurred_on}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {words(cost.approval_status)}
                                {cost.approver_name
                                    ? ` · Reviewer: ${cost.approver_name}`
                                    : ''}{' '}
                                · Recorded by {cost.recorded_by_name}
                            </p>
                            {editable && (
                                <div className="flex flex-wrap gap-2">
                                    {cost.recorded_by === actorId &&
                                        cost.approval_status !== 'approved' && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    open({
                                                        operation: 'cost',
                                                        title: 'Correct recorded cost',
                                                        payload: {
                                                            ...cost,
                                                            ...cost.details,
                                                            reason: '',
                                                        },
                                                    })
                                                }
                                            >
                                                Correct cost
                                            </Button>
                                        )}
                                    {reviewButtons('cost', cost)}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                {!costs.length && (
                    <p className="text-sm text-muted-foreground">
                        No parts or expenses recorded.
                    </p>
                )}
            </section>
            <section
                hidden={tab !== 'people'}
                className="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <div className="flex flex-wrap justify-between gap-3">
                    <h2 className="text-section-title">People & diagnostics</h2>
                    {editable && (
                        <Button
                            onClick={() =>
                                open({
                                    operation: 'context',
                                    title: 'Edit ticket context',
                                    payload: {
                                        ...work.details,
                                        requester_user_id: work.requester?.id,
                                        affected_user_id:
                                            work.affected_user?.id,
                                        reason: '',
                                    },
                                })
                            }
                        >
                            <Pencil className="size-4" />
                            Edit details
                        </Button>
                    )}
                </div>
                <TicketWorkContext
                    work={work}
                    onDetails={() =>
                        open({
                            operation: 'context',
                            title: 'Edit ticket context',
                            payload: { ...work.details, reason: '' },
                        })
                    }
                />
                <TicketDiagnosticSummary
                    work={work}
                    onDetails={() =>
                        open({
                            operation: 'context',
                            title: 'Edit ticket context',
                            payload: { ...work.details, reason: '' },
                        })
                    }
                />
            </section>
            <section
                hidden={tab !== 'corrections'}
                className="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <h2 className="text-section-title">
                    Work history & corrections
                </h2>
                <p className="text-sm text-muted-foreground">
                    Original values and reasons stay available. Public replies
                    retain their delivered content; add a follow-up to correct
                    them.
                </p>
                {editable &&
                    notes
                        .filter(
                            (note) =>
                                note.is_internal && note.author.id === actorId,
                        )
                        .map((note) => (
                            <div
                                key={note.id}
                                className="flex items-center justify-between gap-3 border-b border-border py-2"
                            >
                                <p className="truncate text-sm">
                                    Note #{note.id} · {note.body}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        open({
                                            operation: 'correct_note',
                                            title: 'Correct internal note',
                                            payload: {
                                                id: note.id,
                                                body: note.body,
                                                reason: '',
                                            },
                                        })
                                    }
                                >
                                    Correct note
                                </Button>
                            </div>
                        ))}
                {(work.revisions ?? []).map((revision) => (
                    <details
                        key={revision.id}
                        className="rounded-lg border border-border p-3"
                    >
                        <summary className="frontline-focus cursor-pointer text-sm">
                            {revision.actor_name} · {words(revision.action)}{' '}
                            {revision.record_type} #{revision.record_id} ·{' '}
                            {formatDateTime(revision.created_at)}
                        </summary>
                        <p className="mt-3 text-sm">
                            {revision.evidence.reason}
                        </p>
                        <div className="mt-3 grid gap-4 md:grid-cols-2">
                            {(['before', 'after'] as const).map((side) => (
                                <div key={side}>
                                    <h3 className="text-sm font-semibold capitalize">
                                        {side}
                                    </h3>
                                    <RevisionValues
                                        value={revision.evidence[side]}
                                    />
                                </div>
                            ))}
                        </div>
                    </details>
                ))}
            </section>
            <section
                hidden={tab !== 'approvals'}
                className="space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2 className="text-section-title">Time and cost reviews</h2>
                {[
                    ...entries.map((entry) => ({
                        type: 'time',
                        item: entry,
                        label: `${entry.technician_name} · ${formatDurationMinutes(entry.minutes)}`,
                    })),
                    ...costs.map((cost) => ({
                        type: 'cost',
                        item: cost,
                        label: `${cost.details.description} · ${money(cost.total_cents)}`,
                    })),
                ]
                    .filter(
                        ({ item }) => item.approval_status !== 'not_required',
                    )
                    .map(({ type, item, label }) => (
                        <div
                            key={`${type}-${item.id}`}
                            className="space-y-2 rounded-lg border border-border p-3"
                        >
                            <p className="text-sm font-medium">
                                {label} · {words(item.approval_status)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Assigned reviewer:{' '}
                                {item.approver_name ?? 'Unavailable'}
                            </p>
                            {editable && reviewButtons(type, item)}
                        </div>
                    ))}
            </section>
            <Dialog
                open={!!proposal}
                onOpenChange={(value) => {
                    if (!value && !busy) setDiscard(true);
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{proposal?.title}</DialogTitle>
                        <DialogDescription>
                            Save with the current ticket version. Changes and
                            review decisions retain a reason in the ticket’s
                            internal history.
                        </DialogDescription>
                    </DialogHeader>
                    {recoveryControls}
                    {error && (
                        <p
                            role="alert"
                            className="rounded-lg border border-status-warning/30 bg-status-warning/10 p-3 text-sm"
                        >
                            {error}
                        </p>
                    )}
                    <fieldset
                        disabled={
                            busy || !!pending || !editable || draftBlocked
                        }
                        className="space-y-4"
                    >
                        {proposal?.operation === 'book' && (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <WorkField label="Visit starts">
                                        <Input
                                            type="datetime-local"
                                            value={str('starts_at')}
                                            onChange={(e) =>
                                                change(
                                                    'starts_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </WorkField>
                                    <WorkField label="Visit ends">
                                        <Input
                                            type="datetime-local"
                                            value={str('ends_at')}
                                            onChange={(e) =>
                                                change(
                                                    'ends_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </WorkField>
                                </div>
                                {str('starts_at') && str('ends_at') ? (
                                    <TicketWorkPersonPicker
                                        ticketId={ticketId}
                                        label="Search additional technicians"
                                        startsAt={str('starts_at')}
                                        endsAt={str('ends_at')}
                                        onSelect={(person) => {
                                            const next = [
                                                ...selectedTechs.filter(
                                                    (p) => p.id !== person.id,
                                                ),
                                                person,
                                            ];
                                            setSelectedTechs(next);
                                            change(
                                                'technician_user_ids',
                                                next.map((p) => p.id),
                                            );
                                        }}
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Choose a period to search technicians
                                        and check availability.
                                    </p>
                                )}
                                {selectedTechs.map((person) => (
                                    <div
                                        className="flex items-center gap-2 text-sm"
                                        key={person.id}
                                    >
                                        {person.name}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                const next =
                                                    selectedTechs.filter(
                                                        (p) =>
                                                            p.id !== person.id,
                                                    );
                                                setSelectedTechs(next);
                                                change(
                                                    'technician_user_ids',
                                                    next.map((p) => p.id),
                                                );
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                                {text('location', 'Location')}
                                {text('brief', 'Work brief', true)}
                            </>
                        )}
                        {proposal?.operation === 'booking' && (
                            <>
                                {['reschedule', 'restore'].includes(
                                    str('action'),
                                ) && (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <WorkField label="New visit starts">
                                            <Input
                                                type="datetime-local"
                                                value={workLocal(
                                                    str('starts_at'),
                                                )}
                                                onChange={(e) =>
                                                    change(
                                                        'starts_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </WorkField>
                                        <WorkField label="New visit ends">
                                            <Input
                                                type="datetime-local"
                                                value={workLocal(
                                                    str('ends_at'),
                                                )}
                                                onChange={(e) =>
                                                    change(
                                                        'ends_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </WorkField>
                                    </div>
                                )}
                                {reason()}
                            </>
                        )}
                        {proposal?.operation === 'correct_time' && (
                            <>
                                <TicketWorkPeriodFields
                                    value={
                                        proposal.payload as unknown as WorkEntry
                                    }
                                    onChange={(period) =>
                                        setProposal({
                                            ...proposal,
                                            payload: {
                                                ...proposal.payload,
                                                ...period,
                                            },
                                        })
                                    }
                                />
                                <TicketWorkPersonPicker
                                    ticketId={ticketId}
                                    label="Reviewer, if required"
                                    onSelect={(person) =>
                                        change('approver_user_id', person.id)
                                    }
                                />
                                {reason()}
                            </>
                        )}
                        {proposal?.operation === 'correct_note' && (
                            <>
                                {text('body', 'Corrected internal note', true)}
                                {reason()}
                            </>
                        )}
                        {proposal?.operation === 'review' && (
                            <>
                                <p className="text-sm">
                                    Decision: {words(str('decision'))}
                                </p>
                                {reason()}
                            </>
                        )}
                        {proposal?.operation === 'cost' && (
                            <>
                                <WorkSelect
                                    label="Cost type"
                                    value={str('kind')}
                                    onChange={(value) => change('kind', value)}
                                    options={[
                                        ['part', 'Part'],
                                        ['expense', 'Expense'],
                                        ['travel_expense', 'Travel expense'],
                                    ]}
                                />
                                {text('description', 'Description', true)}
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <WorkField label="Incurred on">
                                        <Input
                                            type="date"
                                            value={str('incurred_on')}
                                            onChange={(e) =>
                                                change(
                                                    'incurred_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </WorkField>
                                    <WorkField label="Quantity">
                                        <Input
                                            type="number"
                                            min={0.01}
                                            step={0.01}
                                            value={
                                                num('quantity_hundredths') / 100
                                            }
                                            onChange={(e) =>
                                                change(
                                                    'quantity_hundredths',
                                                    Math.round(
                                                        Number(e.target.value) *
                                                            100,
                                                    ),
                                                )
                                            }
                                        />
                                    </WorkField>
                                    <WorkField label="Unit cost (NZD)">
                                        <Input
                                            type="number"
                                            min={0}
                                            step={0.01}
                                            value={num('unit_cost_cents') / 100}
                                            onChange={(e) =>
                                                change(
                                                    'unit_cost_cents',
                                                    Math.round(
                                                        Number(e.target.value) *
                                                            100,
                                                    ),
                                                )
                                            }
                                        />
                                    </WorkField>
                                </div>
                                {text(
                                    'reference',
                                    'Receipt or purchase reference',
                                )}
                                <p className="text-sm font-semibold">
                                    Total{' '}
                                    {money(
                                        Math.round(
                                            (num('quantity_hundredths') *
                                                num('unit_cost_cents')) /
                                                100,
                                        ),
                                    )}
                                </p>
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={
                                            !!proposal.payload.require_approval
                                        }
                                        onChange={(e) =>
                                            change(
                                                'require_approval',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Require review before resolution
                                </label>
                                <TicketWorkPersonPicker
                                    ticketId={ticketId}
                                    label="Assigned reviewer, if required"
                                    onSelect={(person) => {
                                        change('approver_user_id', person.id);
                                    }}
                                />
                                {proposal.payload.id ? reason() : null}
                            </>
                        )}
                        {proposal?.operation === 'context' && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Changing the requester or affected user
                                    gives the selected person access to this
                                    ticket’s public history. Work email and work
                                    phone come from the staff directory.
                                </p>
                                {(
                                    [
                                        [
                                            'requester_user_id',
                                            'Requester',
                                            work.requester,
                                        ],
                                        [
                                            'affected_user_id',
                                            'Affected user',
                                            work.affected_user,
                                        ],
                                        [
                                            'alternate_user_id',
                                            'Alternate contact',
                                            work.alternate_contact,
                                        ],
                                    ] as const
                                ).map(([key, label, person]) => (
                                    <TicketWorkPersonPicker
                                        key={key}
                                        ticketId={ticketId}
                                        kind="user"
                                        label={label}
                                        selected={
                                            proposal.payload[`${key}_name`]
                                                ? {
                                                      id: num(key),
                                                      name: str(`${key}_name`),
                                                  }
                                                : person
                                        }
                                        onSelect={(selected) =>
                                            setProposal({
                                                ...proposal,
                                                payload: {
                                                    ...proposal.payload,
                                                    [key]: selected.id,
                                                    [`${key}_name`]:
                                                        selected.name,
                                                },
                                            })
                                        }
                                    />
                                ))}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {text(
                                        'preferred_channel',
                                        'Preferred contact method',
                                    )}
                                    {text('callback_window', 'Contact window')}
                                    {text('location', 'Location')}
                                    {text('onset', 'First noticed')}
                                    <WorkField label="Number of people affected">
                                        <Input
                                            type="number"
                                            min={1}
                                            value={str('affected_count')}
                                            onChange={(e) =>
                                                change(
                                                    'affected_count',
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : null,
                                                )
                                            }
                                        />
                                    </WorkField>
                                </div>
                                {text('impact_summary', 'Impact', true)}
                                {text('workaround', 'Workaround', true)}
                                {text(
                                    'already_tried',
                                    'Checks already tried',
                                    true,
                                )}
                                {text(
                                    'access_instructions',
                                    'Access instructions — no passwords',
                                    true,
                                )}
                                {text(
                                    'vendor_reference',
                                    'Vendor case reference',
                                )}
                                {text(
                                    'support_reference',
                                    'Support agreement reference',
                                )}
                                <details>
                                    <summary className="frontline-focus cursor-pointer text-sm font-semibold">
                                        Internal rates and ticket-specific
                                        review rules
                                    </summary>
                                    <div className="mt-3 space-y-3">
                                        <p className="text-xs text-muted-foreground">
                                            Enter agreed internal rates. Blank
                                            means unpriced. Changes affect newly
                                            recorded time; existing records
                                            retain their rate.
                                        </p>
                                        {[
                                            [
                                                'standard_rate_cents',
                                                'Standard hourly rate (NZD)',
                                            ],
                                            [
                                                'after_hours_rate_cents',
                                                'After-hours hourly rate (NZD)',
                                            ],
                                            [
                                                'cost_review_threshold_cents',
                                                'Require review from cost amount (NZD)',
                                            ],
                                        ].map(([key, label]) => (
                                            <WorkField key={key} label={label}>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    step={0.01}
                                                    value={
                                                        proposal.payload[key] ==
                                                        null
                                                            ? ''
                                                            : num(key) / 100
                                                    }
                                                    onChange={(e) =>
                                                        change(
                                                            key,
                                                            e.target.value ===
                                                                ''
                                                                ? null
                                                                : Math.round(
                                                                      Number(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      ) * 100,
                                                                  ),
                                                        )
                                                    }
                                                />
                                            </WorkField>
                                        ))}
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    !!proposal.payload
                                                        .review_after_hours
                                                }
                                                onChange={(e) =>
                                                    change(
                                                        'review_after_hours',
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                            Require a review of new after-hours
                                            entries
                                        </label>
                                    </div>
                                </details>
                                {reason()}
                            </>
                        )}
                    </fieldset>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setDiscard(true)}
                        >
                            Cancel
                        </Button>
                        {stale ? (
                            <Button
                                onClick={() => {
                                    setPending(null);
                                    setStale(false);
                                    setBaseVersion(version);
                                    setError(
                                        'Review your retained fields against the refreshed ticket, then save again.',
                                    );
                                }}
                            >
                                Review against refreshed ticket
                            </Button>
                        ) : (
                            <Button
                                disabled={busy || !editable || draftBlocked}
                                onClick={() => void save()}
                            >
                                {busy
                                    ? 'Saving…'
                                    : pending
                                      ? 'Retry same save'
                                      : 'Save'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title={
                    pending || recoveryPending
                        ? 'Cancel the unconfirmed request?'
                        : 'Discard entered changes?'
                }
                description={
                    pending || recoveryPending
                        ? 'The server will cancel this request if it has not saved yet. If it already saved, the ticket will be refreshed and the saved record will remain in place.'
                        : 'Your entered fields and saved draft will be discarded. Saved ticket records remain in place.'
                }
                confirmText={
                    pending || recoveryPending
                        ? 'Cancel request'
                        : 'Discard changes'
                }
                onConfirm={() => void discardWork()}
            />
        </>
    );
}

function RevisionValues({ value }: { value: unknown }) {
    if (value === null || value === undefined)
        return (
            <p className="text-sm text-muted-foreground">No previous value</p>
        );
    if (typeof value !== 'object')
        return <p className="text-sm whitespace-pre-wrap">{String(value)}</p>;
    return (
        <dl className="space-y-1 text-xs">
            {Object.entries(value)
                .filter(
                    ([key]) =>
                        ![
                            'ticket_id',
                            'created_at',
                            'updated_at',
                            'id',
                        ].includes(key),
                )
                .map(([key, item]) => (
                    <div key={key} className="break-words">
                        <dt className="font-medium capitalize">{words(key)}</dt>
                        <dd>
                            {item && typeof item === 'object' ? (
                                <RevisionValues value={item} />
                            ) : (
                                String(item ?? 'Not recorded')
                            )}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

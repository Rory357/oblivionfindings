import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import type { ItDraftSnapshot } from '@/hooks/it-ticket-draft-contract';
import { duplicateReasonLabels } from '@/hooks/it-ticket-duplicate-contract';
import type {
    ItMergeIdentity,
    ItMergeResult,
} from '@/hooks/it-ticket-merge-contract';
import { useItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { useItTicketMergeCommand } from '@/hooks/use-it-ticket-merge-command';
import { useItTicketMergePreview } from '@/hooks/use-it-ticket-merge-preview';
import { router } from '@inertiajs/react';
import {
    Archive,
    ClipboardCheck,
    FileText,
    GitMerge,
    MessageSquare,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { TicketVersionConflict } from './ticket-version-conflict';

export interface MergeTarget {
    id: number;
    reference: string | null;
    title: string;
    priority: string;
    status: string;
    lock_version: number;
    duplicate_reasons?: ('same_title' | 'same_service_and_title_words')[];
}
interface Props {
    actorId: number;
    ticket: {
        id: number;
        reference: string | null;
        title: string;
        lock_version: number;
    };
    targets: MergeTarget[];
    onClose: () => void;
}
const steps = [
    {
        key: 'choose',
        label: 'Choose survivor',
        blurb: 'Keep the right working ticket',
        icon: GitMerge,
    },
    {
        key: 'review',
        label: 'Review merge',
        blurb: 'Check records and retained evidence',
        icon: ClipboardCheck,
    },
] as const;
const fields = ['reason', 'target_ticket_id', 'target_version'] as const;

/** One mounted proposal belongs to one actor and source; page refreshes cannot replace it. */
export function MergeTicketDialog(props: Props) {
    return <MergeBody key={`${props.actorId}:${props.ticket.id}`} {...props} />;
}

function MergeBody({ actorId, ticket, targets, onClose }: Props) {
    const [sourceVersion, setSourceVersion] = useState(ticket.lock_version);
    const [chosen, setChosen] = useState<MergeTarget | null>(null);
    const [reason, setReason] = useState('');
    const [query, setQuery] = useState('');
    const [step, setStep] = useState(0);
    const [acknowledged, setAcknowledged] = useState(false);
    const [access, setAccess] = useState<'access' | 'session' | null>(null);
    const [needsCurrent, setNeedsCurrent] = useState(false);
    const [result, setResult] = useState<ItMergeResult | null>(null);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const [cancelReference, setCancelReference] =
        useState<ItMergeIdentity | null>(null);
    const [discardBuffer, setDiscardBuffer] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [resumed, setResumed] = useState(false);
    const memoryRef = useRef<ReturnType<typeof useItTicketDraftMemory> | null>(
        null,
    );
    const previewRef = useRef<ReturnType<
        typeof useItTicketMergePreview
    > | null>(null);
    const form = useRef<HTMLDivElement>(null);
    const focusReturn = useRef<HTMLElement | null>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const openSurvivor = useRef<HTMLButtonElement>(null);
    const approvedNavigation = useRef(false);
    const commandRef = useRef<ReturnType<
        typeof useItTicketMergeCommand
    > | null>(null);
    const conceal = useCallback((kind: 'access' | 'session') => {
        previewRef.current?.cancel();
        setAccess(kind);
        setAcknowledged(false);
        setLeave(null);
        if (kind === 'access') {
            memoryRef.current?.clearCurrentScope();
            setReason('');
            setQuery('');
        }
    }, []);
    const deny = useCallback(
        (kind: 'access' | 'session') => {
            commandRef.current?.conceal(kind);
            conceal(kind);
        },
        [conceal],
    );
    const command = useItTicketMergeCommand({
        actorId,
        sourceId: ticket.id,
        onConceal: conceal,
        onSettled: (saved) => {
            setResult(saved);
            setAcknowledged(false);
            previewRef.current?.cancel();
            if (saved.status === 'committed') {
                memoryRef.current?.clearOwnedWork();
                setReason('');
                setChosen(null);
            } else {
                setNeedsCurrent(true);
            }
        },
    });
    const pending = command.references.length > 0 || command.outcomeUnknown;
    const preview = useItTicketMergePreview(
        {
            actorId,
            sourceId: ticket.id,
            sourceVersion,
            targetId: chosen?.id ?? 0,
            targetVersion: chosen?.lock_version ?? 0,
        },
        !command.busy && !pending,
    );
    const snapshot: ItDraftSnapshot = useMemo(
        () => ({
            fields: {
                reason,
                target_ticket_id: chosen?.id ?? null,
                target_version: chosen?.lock_version ?? null,
            },
            step_index: step,
            base_ticket_version: sourceVersion,
        }),
        [reason, chosen, step, sourceVersion],
    );
    const dirty = reason !== '' || chosen !== null;
    const memory = useItTicketDraftMemory({
        enabled: access !== 'access' && result?.status !== 'committed',
        persistenceEnabled: false,
        actorId,
        context: { purpose: 'merge_work', ticketId: ticket.id },
        draft: null,
        workingSnapshot: snapshot,
        workingDirty: dirty,
        outcomeUnknown: command.outcomeUnknown && dirty,
        settledOperationToken: command.settledOperationToken,
        acceptedFields: fields,
        acceptSelectedFiles: false,
        canRecover: !command.busy && !preview.busy,
        onAccessLost: () => deny('access'),
        onSessionExpired: () => deny('session'),
    });
    useLayoutEffect(() => {
        memoryRef.current = memory;
        previewRef.current = preview;
        commandRef.current = command;
    });
    const concealed =
        access !== null ||
        command.concealed ||
        memory.failure === 'session_expired' ||
        memory.failure === 'access_denied';
    const busy = command.busy || preview.busy || memory.busy;
    const recoveryWaiting = memory.notices.length > 0 && !resumed;
    const canEdit =
        !concealed &&
        !busy &&
        !pending &&
        !recoveryWaiting &&
        result?.status !== 'committed';
    const review = preview.preview;
    const stale =
        sourceVersion < ticket.lock_version ||
        (chosen !== null &&
            targets.some(
                (target) =>
                    target.id === chosen.id &&
                    target.lock_version > chosen.lock_version,
            ));
    const guarded = result?.status !== 'committed' && (dirty || pending);
    useLayoutEffect(() => {
        const body = form.current?.closest('[data-wizard-region="body"]');
        if (body instanceof HTMLElement) body.scrollTop = 0;
        heading.current?.focus({ preventScroll: true });
    }, [step]);
    const restoreFocus = (event: Event) => {
        if (!form.current?.isConnected) return;
        event.preventDefault();
        const previous = focusReturn.current;
        if (
            previous?.isConnected &&
            !previous.matches('[disabled], [aria-disabled="true"]')
        )
            previous.focus({ preventScroll: true });
        else heading.current?.focus({ preventScroll: true });
    };
    const close = () => {
        if (busy) {
            setError('Stop waiting before closing this merge form.');
            return;
        }
        if (guarded) setLeave(() => onClose);
        else onClose();
    };
    useEffect(() => {
        if (!guarded) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (
                approvedNavigation.current ||
                event.detail.visit.method !== 'get'
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave(() => () => {
                approvedNavigation.current = true;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [guarded]);
    useEffect(() => {
        if (step === 1 || error || command.message)
            heading.current?.focus({ preventScroll: true });
    }, [step, error, command.message]);
    useEffect(() => {
        if (result?.status === 'committed')
            openSurvivor.current?.focus({ preventScroll: true });
    }, [result]);
    useEffect(() => {
        if (preview.failure === 'session') deny('session');
        if (preview.failure === 'access') deny('access');
        if (preview.failure === 'stale') setNeedsCurrent(true);
    }, [preview.failure, deny]);
    const loadReview = async () => {
        if (busy || pending) return;
        if (!chosen) {
            setError(
                'Choose the ticket that should survive before reviewing the merge.',
            );
            return;
        }
        if (!reason.trim() && !concealed) {
            setError('Explain why these tickets are duplicates.');
            return;
        }
        setAcknowledged(false);
        setError(null);
        const fresh = await preview.load();
        if (!fresh) return;
        if (!command.reviewed()) return;
        setAccess(null);
        setNeedsCurrent(false);
        setResult(null);
        setStep(1);
    };
    const resume = async (bufferId: string) => {
        const restored = await memory.resume(bufferId);
        if (!restored || restored.candidate.context.purpose !== 'merge_work')
            return;
        const original = restored.candidate.snapshot;
        setReason(original.fields.reason ?? '');
        const id = original.fields.target_ticket_id;
        const version = original.fields.target_version;
        setChosen(
            id && version
                ? {
                      id,
                      lock_version: version,
                      reference: null,
                      title: 'Retained selection — review current ticket',
                      priority: '',
                      status: '',
                  }
                : null,
        );
        setSourceVersion(original.base_ticket_version ?? sourceVersion);
        setStep(0);
        setNeedsCurrent(true);
        setResumed(true);
        setAccess(null);
        setAcknowledged(false);
    };
    const keepAndClose = () => {
        if (busy) return;
        const retained = memory.ensureLatestRetained();
        if (retained.status === 'blocked') {
            setError(retained.message);
            return;
        }
        onClose();
    };
    const filtered = targets.filter((target) =>
        `${target.reference ?? ''} ${target.title}`
            .toLowerCase()
            .includes(query.trim().toLowerCase()),
    );
    const confirmed = result?.status === 'committed' ? result : null;
    const currentReview =
        needsCurrent || stale || command.stage === 'rejected' || concealed;
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={concealed ? 'Merge details unavailable' : 'Merge ticket'}
                description="Review two tickets and preserve their evidence before merging a duplicate."
                railIcon={GitMerge}
                railTitle="Merge tickets"
                railSub="IT & Support"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!canEdit) return;
                    if (index === 0) {
                        setStep(0);
                        setAcknowledged(false);
                    } else void loadReview();
                }}
                pct={null}
                success={
                    confirmed ? (
                        <WizardSuccessPane
                            title="Ticket merged"
                            blurb="The saved merge is confirmed. Conversation and files are on the surviving ticket; original work and approval evidence remain preserved."
                            actions={
                                <Button
                                    ref={openSurvivor}
                                    onClick={() => {
                                        approvedNavigation.current = true;
                                        onClose();
                                        router.visit(confirmed.url);
                                    }}
                                >
                                    Open surviving ticket
                                </Button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    dirty && !busy && !confirmed ? (
                        <Button variant="ghost" onClick={keepAndClose}>
                            Keep draft and close
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={(event) => {
                                focusReturn.current = event.currentTarget;
                                close();
                            }}
                        >
                            Close
                        </Button>
                        {busy ? (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    if (command.busy) command.stop();
                                    else if (memory.busy) memory.cancel();
                                    else preview.cancel();
                                }}
                            >
                                Stop waiting
                            </Button>
                        ) : step === 0 || !review || currentReview ? (
                            <Button
                                onClick={() => void loadReview()}
                                disabled={
                                    !chosen ||
                                    pending ||
                                    recoveryWaiting ||
                                    (!reason.trim() && !concealed)
                                }
                            >
                                Review merge
                            </Button>
                        ) : (
                            <Button
                                disabled={
                                    !canEdit ||
                                    !acknowledged ||
                                    review.lifecycle_blockers.length > 0 ||
                                    !reason.trim()
                                }
                                onClick={() =>
                                    void command.send(review, reason)
                                }
                            >
                                Merge into{' '}
                                {review.target.reference ??
                                    `#${review.target.id}`}
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane>
                    <div
                        ref={form}
                        onFocusCapture={(event) => {
                            if (event.target instanceof HTMLElement)
                                focusReturn.current = event.target;
                        }}
                        className="space-y-4"
                    >
                        <h2 ref={heading} tabIndex={-1} className="sr-only">
                            {step === 0
                                ? 'Choose the surviving ticket'
                                : 'Review this merge'}
                        </h2>
                        {(error || command.message || memory.warning) && (
                            <div
                                role="status"
                                className="rounded-lg border border-border bg-muted/30 p-3 text-sm"
                            >
                                {error && <p>{error}</p>}
                                {command.message && <p>{command.message}</p>}
                                {memory.warning && <p>{memory.warning}</p>}
                            </div>
                        )}
                        {busy && (
                            <p role="status" className="text-sm">
                                {preview.busy
                                    ? 'Reading both tickets…'
                                    : memory.busy
                                      ? 'Checking access to the retained draft…'
                                      : 'Waiting for the recorded command result…'}
                            </p>
                        )}
                        {preview.failure === 'failed' && (
                            <p role="alert">
                                The review could not be loaded. Your proposal is
                                retained; try Review merge again.
                            </p>
                        )}
                        {preview.failure === 'cancelled' && !confirmed && (
                            <p role="status">
                                The review wait stopped. No merge was submitted
                                by that read.
                            </p>
                        )}
                        {result?.status === 'cancelled' && (
                            <p role="status">
                                The merge command was cancelled. Your reason is
                                retained; review both tickets before starting
                                another merge.
                            </p>
                        )}
                        {command.references.map((reference, index) => (
                            <div
                                key={reference.requestUuid}
                                className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3"
                            >
                                <p className="mr-auto text-sm">
                                    Pending merge {index + 1}
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void command.check(reference)
                                    }
                                >
                                    Check result {index + 1}
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        setCancelReference(reference)
                                    }
                                >
                                    Cancel command {index + 1}
                                </Button>
                            </div>
                        ))}
                        {command.canRetry && !busy && (
                            <Button
                                variant="outline"
                                onClick={() => void command.retry()}
                            >
                                Retry original merge
                            </Button>
                        )}
                        {memory.notices.map((notice, index) => (
                            <div
                                key={notice.bufferId}
                                className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3"
                            >
                                <p className="mr-auto text-sm">
                                    Retained merge draft {index + 1}
                                    {notice.outcomeUnknown
                                        ? ' · result unconfirmed'
                                        : ''}
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void resume(notice.bufferId)}
                                >
                                    Resume draft {index + 1}
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={busy || notice.outcomeUnknown}
                                    onClick={() =>
                                        setDiscardBuffer(notice.bufferId)
                                    }
                                >
                                    Discard draft {index + 1}
                                </Button>
                            </div>
                        ))}
                        {concealed && (
                            <InfoCard icon={Archive}>
                                Merge details are concealed. Return using the
                                original account and review current access.
                                Pending commands can still be checked or
                                cancelled when access is restored.
                            </InfoCard>
                        )}
                        {currentReview && !pending && (
                            <div className="space-y-3">
                                <p className="text-sm">
                                    Your proposal keeps its original versions.
                                    Read and adopt the current records, then
                                    review the merge again. Reading does not
                                    submit it.
                                </p>
                                <section aria-label="Original ticket version">
                                    <TicketVersionConflict
                                        ticketId={ticket.id}
                                        actorId={actorId}
                                        error="Review the original ticket."
                                        adoptLabel="Use this original version"
                                        onReviewed={(version) => {
                                            setSourceVersion(version);
                                            preview.reset();
                                            setAcknowledged(false);
                                        }}
                                        onAccessLost={() => deny('access')}
                                        onSessionLost={() => deny('session')}
                                    />
                                </section>
                                {chosen && (
                                    <section aria-label="Surviving ticket version">
                                        <TicketVersionConflict
                                            ticketId={chosen.id}
                                            actorId={actorId}
                                            error="Review the surviving ticket."
                                            adoptLabel="Use this surviving version"
                                            onReviewed={(version, current) => {
                                                setChosen((previous) =>
                                                    previous
                                                        ? {
                                                              ...previous,
                                                              lock_version:
                                                                  version,
                                                              ...(current
                                                                  ? {
                                                                        title: current.title,
                                                                        reference:
                                                                            current.reference,
                                                                        status: current.status,
                                                                        priority:
                                                                            current.priority,
                                                                    }
                                                                  : {}),
                                                          }
                                                        : null,
                                                );
                                                preview.reset();
                                                setAcknowledged(false);
                                            }}
                                            onAccessLost={() => deny('access')}
                                            onSessionLost={() =>
                                                deny('session')
                                            }
                                        />
                                    </section>
                                )}
                            </div>
                        )}
                        {!concealed && step === 0 && (
                            <>
                                <StepHead
                                    icon={GitMerge}
                                    title="Choose the surviving ticket"
                                    blurb="Keep the ticket that should carry the conversation forward. The duplicate will close with a link to its preserved history."
                                />
                                <Input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    disabled={!canEdit}
                                    placeholder="Search by reference or title…"
                                    aria-label="Search merge targets"
                                />
                                <p className="text-caption text-muted-foreground">
                                    Up to 50 eligible tickets from the 100 most
                                    recent open reports by this requester.
                                    Possible duplicates appear first. Check the
                                    details before deciding whether to merge.
                                </p>
                                <div
                                    className="max-h-60 space-y-2 overflow-y-auto"
                                    aria-label="Merge targets"
                                >
                                    {filtered.length === 0 ? (
                                        <p className="rounded-lg border border-dashed border-border p-4 text-sm">
                                            {targets.length === 0
                                                ? 'No eligible open candidates are listed. Pending merge recovery remains available above.'
                                                : 'No tickets match this search.'}
                                        </p>
                                    ) : (
                                        filtered.map((target) => (
                                            <Button
                                                key={target.id}
                                                variant="outline"
                                                aria-pressed={
                                                    chosen?.id === target.id
                                                }
                                                disabled={!canEdit}
                                                className="h-auto min-h-11 w-full justify-start text-left whitespace-normal"
                                                onClick={() => {
                                                    setChosen(target);
                                                    setAcknowledged(false);
                                                    preview.reset();
                                                    setError(null);
                                                }}
                                            >
                                                <span className="min-w-0">
                                                    <span className="block text-xs text-muted-foreground">
                                                        {target.reference ??
                                                            `#${target.id}`}
                                                    </span>
                                                    <span className="block break-words">
                                                        {target.title}
                                                    </span>
                                                    {target.duplicate_reasons?.map(
                                                        (reason) => (
                                                            <span
                                                                key={reason}
                                                                className="text-caption block text-muted-foreground"
                                                            >
                                                                Possible
                                                                duplicate ·{' '}
                                                                {
                                                                    duplicateReasonLabels[
                                                                        reason
                                                                    ]
                                                                }
                                                            </span>
                                                        ),
                                                    )}
                                                </span>
                                            </Button>
                                        ))
                                    )}
                                </div>
                                {chosen && (
                                    <p className="text-sm">
                                        Selected survivor:{' '}
                                        {chosen.reference ?? `#${chosen.id}`}
                                    </p>
                                )}
                                <Field
                                    label="Reason for merging"
                                    required
                                    hint="Internal history on both tickets."
                                >
                                    <Textarea
                                        rows={3}
                                        maxLength={1000}
                                        value={reason}
                                        disabled={!canEdit}
                                        onChange={(event) => {
                                            setReason(event.target.value);
                                            setError(null);
                                        }}
                                    />
                                </Field>
                                <InfoCard icon={GitMerge}>
                                    Only tickets for the same requester and
                                    requested-for person are listed. The review
                                    also checks site, staff access and
                                    unfinished work. A recorded merge cannot be
                                    undone.
                                </InfoCard>
                            </>
                        )}
                        {!concealed && step === 1 && review && (
                            <>
                                <StepHead
                                    icon={ClipboardCheck}
                                    title="Review this merge"
                                    blurb="Check the survivor, the records that move and the evidence retained on the original ticket."
                                />
                                <div className="grid grid-cols-2 gap-3">
                                    <ReviewCard
                                        icon={Archive}
                                        title="Original · closes"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            {review.source.reference ??
                                                `#${review.source.id}`}
                                        </p>
                                        <p className="text-sm font-medium break-words">
                                            {review.source.title}
                                        </p>
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={GitMerge}
                                        title="Survivor · continues"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            {review.target.reference ??
                                                `#${review.target.id}`}
                                        </p>
                                        <p className="text-sm font-medium break-words">
                                            {review.target.title}
                                        </p>
                                    </ReviewCard>
                                </div>
                                <ReviewCard
                                    icon={MessageSquare}
                                    title="Move to the survivor"
                                >
                                    <ReviewRow
                                        label="Public messages"
                                        value={
                                            review.source.inventory
                                                .public_comments
                                        }
                                    />
                                    <ReviewRow
                                        label="Internal notes"
                                        value={
                                            review.source.inventory
                                                .internal_notes
                                        }
                                    />
                                    <ReviewRow
                                        label="Files"
                                        value={
                                            review.source.inventory
                                                .ticket_files +
                                            review.source.inventory
                                                .comment_files
                                        }
                                    />
                                    <ReviewRow
                                        label="Watchers"
                                        value={review.source.inventory.watchers}
                                    />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        File identities and comment visibility
                                        are preserved. Watchers must still be
                                        eligible when the merge commits.
                                    </p>
                                </ReviewCard>
                                <ReviewCard
                                    icon={Archive}
                                    title="Preserve on the original"
                                >
                                    <ReviewRow
                                        label="Tasks and their evidence"
                                        value={review.source.inventory.tasks}
                                    />
                                    <ReviewRow
                                        label="Approval requests"
                                        value={
                                            review.source.inventory
                                                .approval_requests
                                        }
                                    />
                                    <ReviewRow
                                        label="Linked records"
                                        value={review.source.inventory.links}
                                    />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Completed work and approval history
                                        retain their original IDs. They remain
                                        available from the original record.
                                    </p>
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Internal merge reason"
                                >
                                    <p className="text-sm break-words whitespace-pre-wrap">
                                        {reason}
                                    </p>
                                </ReviewCard>
                                {review.access_scope_differences.length > 0 && (
                                    <p className="text-sm">
                                        Different settings:{' '}
                                        {review.access_scope_differences
                                            .map(
                                                (difference) =>
                                                    ({
                                                        site_id: 'site',
                                                        is_organisation_wide:
                                                            'organisation-wide scope',
                                                        is_sensitive:
                                                            'sensitivity',
                                                        requester_user_id:
                                                            'requester',
                                                        requested_for_user_id:
                                                            'requested-for person',
                                                        assigned_to_user_id:
                                                            'assignee',
                                                        owner_user_id: 'owner',
                                                        team_id: 'team',
                                                        queue_id: 'queue',
                                                    })[difference.field],
                                            )
                                            .join(', ')}
                                        . Access must remain compatible.
                                    </p>
                                )}
                                {review.lifecycle_blockers.length > 0 ? (
                                    <div
                                        role="alert"
                                        className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm"
                                    >
                                        <p className="font-semibold">
                                            Resolve before merging
                                        </p>
                                        <ul className="mt-2 list-disc pl-5">
                                            {review.lifecycle_blockers.map(
                                                (blocker) => (
                                                    <li key={blocker}>
                                                        {blocker}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                ) : (
                                    <label className="flex items-start gap-3 text-sm">
                                        <Checkbox
                                            checked={acknowledged}
                                            disabled={!canEdit || currentReview}
                                            onCheckedChange={(checked) =>
                                                setAcknowledged(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span>
                                            I have checked the survivor and
                                            retained evidence. This merge cannot
                                            be undone.
                                        </span>
                                    </label>
                                )}
                                <Button
                                    variant="outline"
                                    disabled={!canEdit}
                                    onClick={() => {
                                        setStep(0);
                                        setAcknowledged(false);
                                    }}
                                >
                                    Back to proposal
                                </Button>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    memory.clearOwnedWork();
                    approvedNavigation.current = true;
                    leave?.();
                }}
                title={
                    pending
                        ? 'Leave with a merge result still unconfirmed?'
                        : 'Discard this merge draft?'
                }
                description={
                    pending
                        ? 'The merge may still finish. Its recovery reference will remain available, but this removes the editable browser copy. It does not cancel or undo a merge.'
                        : 'This removes the current browser proposal. No merge has been submitted by this close action. Cancel keeps your draft open.'
                }
                confirmText={
                    pending
                        ? 'Leave and keep recovery reference'
                        : 'Discard draft and leave'
                }
                onCloseAutoFocus={restoreFocus}
            />
            <ConfirmDialog
                open={cancelReference !== null}
                onClose={() => setCancelReference(null)}
                onConfirm={() => {
                    if (cancelReference) void command.cancel(cancelReference);
                }}
                title="Cancel this merge command?"
                description="Cancellation prevents this command from merging if it has not committed. If the merge already committed, its saved result will be shown. Your draft reason is retained."
                confirmText="Cancel merge command"
                onCloseAutoFocus={restoreFocus}
            />
            <ConfirmDialog
                open={discardBuffer !== null}
                onClose={() => setDiscardBuffer(null)}
                onConfirm={() => {
                    if (discardBuffer) memory.discardLocal(discardBuffer);
                }}
                title="Discard retained merge draft?"
                description="Only this retained browser copy is removed. Recorded tickets and merge outcomes are unchanged."
                confirmText="Discard retained draft"
                onCloseAutoFocus={restoreFocus}
            />
        </>
    );
}

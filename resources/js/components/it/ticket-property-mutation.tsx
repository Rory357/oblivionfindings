import { ConfirmDialog } from '@/components/confirm-dialog';
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
    type ItDraftCommitReference,
    type ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { TicketDraftRecovery } from './ticket-draft-recovery';
import { TicketTriageReasonDialog } from './ticket-triage-reason-dialog';
import { TicketVersionConflict } from './ticket-version-conflict';

type TicketPatch = Record<string, string | number | boolean | null>;
const fieldLabels: Record<string, string> = {
    status: 'Status',
    priority: 'Priority',
    work_type: 'Work type',
    it_service_id: 'Affected service',
    category: 'Category',
    subcategory: 'Subcategory',
    asset_id: 'Asset',
    site_id: 'Site',
    is_organisation_wide: 'All Sites',
    assigned_to_user_id: 'Assigned technician',
    queue_id: 'Queue',
    owner_user_id: 'Accountable owner',
    priority_reason: 'Priority reason',
    routing_reason: 'Ownership reason',
    release_priority_override: 'Use assessed priority',
    release_routing_override: 'Use automatic routing',
};
interface PendingChange {
    actorId: number;
    id: number;
    version: number;
    changes: TicketPatch;
    descriptions: Record<string, string>;
}
type Stage =
    | 'editing'
    | 'reason'
    | 'sending'
    | 'unknown'
    | 'reviewed'
    | 'session'
    | 'access';
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) > 0;
const routingFields = [
    'assigned_to_user_id',
    'owner_user_id',
    'queue_id',
    'release_routing_override',
];
const needsReason = (changes: TicketPatch) =>
    (('priority' in changes || changes.release_priority_override === true) &&
        !String(changes.priority_reason ?? '').trim()) ||
    (routingFields.some((field) => field in changes) &&
        !String(changes.routing_reason ?? '').trim());
const valueLabel = (value: unknown) =>
    value === null
        ? 'Clear value'
        : typeof value === 'boolean'
          ? value
              ? 'Yes'
              : 'No'
          : String(value).replaceAll('_', ' ');

/** Original actor and displayed version bind every canonical property intent. */
export function useTicketPropertyMutation({
    actorId,
    draftsEnabled = false,
    onCommitted,
    recoveryTicket,
}: {
    actorId: number | null | undefined;
    draftsEnabled?: boolean;
    onCommitted?: (ticketId: number, version: number) => void;
    recoveryTicket?: { id: number; lock_version: number };
}) {
    const [pending, setPending] = useState<PendingChange | null>(null);
    const pendingRef = useRef(pending);
    pendingRef.current = pending;
    const [stage, setStage] = useState<Stage>('editing');
    const [visible, setVisible] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [leave, setLeave] = useState<{
        run: () => void;
        navigation: boolean;
    } | null>(null);
    const [replacement, setReplacement] = useState<PendingChange | null>(null);
    const [discardProposal, setDiscardProposal] = useState(false);
    const [autoApply, setAutoApply] = useState(false);
    const [settledOperation, setSettledOperation] = useState(0);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const currentActor = useRef(actorId);
    currentActor.current = actorId;
    const approvedNavigation = useRef(false);
    const committed = useRef(onCommitted);
    committed.current = onCommitted;
    const alertRef = useRef<HTMLDivElement>(null);
    const previousRecoveryId = useRef(recoveryTicket?.id ?? null);
    const originalScope =
        pending?.actorId === actorId &&
        (previousRecoveryId.current === null ||
            recoveryTicket?.id === pending?.id);
    const current = originalScope ? pending : null;
    const context = useMemo(
        () => ({
            purpose: 'ticket_edit' as const,
            ticketId: current?.id ?? recoveryTicket?.id ?? 0,
        }),
        [current?.id, recoveryTicket?.id],
    );
    const snapshot: ItDraftSnapshot = useMemo(
        () => ({
            fields: current?.changes ?? {},
            step_index: 0,
            base_ticket_version:
                current?.version ?? recoveryTicket?.lock_version ?? null,
        }),
        [current, recoveryTicket?.lock_version],
    );
    const deny = useCallback(() => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setPending(null);
        setReplacement(null);
        setDiscardProposal(false);
        setLeave(null);
        setErrors({});
        setMessage(
            'Your account or ticket access changed. The proposed changes are concealed. Reload the ticket before continuing.',
        );
        setStage('access');
        setVisible(true);
    }, []);
    const draft = useItTicketDraft({
        enabled: draftsEnabled,
        actorId: actorId ?? undefined,
        context,
        active: positive(context.ticketId) && stage !== 'access',
        onAccessLost: deny,
        workingSnapshot: snapshot,
        workingDirty: current !== null,
        workingOutcomeUnknown: ['sending', 'unknown', 'session'].includes(
            stage,
        ),
        workingSettledOperationToken: settledOperation,
    });
    const draftSaved = draftsEnabled && draft.isSaved(snapshot);
    const concealed =
        stage === 'access' ||
        stage === 'session' ||
        draft.state === 'session_expired' ||
        draft.state === 'access_denied';
    const draftBusy = draftsEnabled && draft.busy;
    const processing = stage === 'sending';
    const editable = stage === 'editing' || stage === 'reviewed';
    const canApply =
        current !== null &&
        editable &&
        !processing &&
        !draftBusy &&
        !draft.memoryBlocked &&
        Object.keys(current.changes).length > 0 &&
        !needsReason(current.changes) &&
        (!draftsEnabled ||
            (draft.state === 'ready' &&
                draftSaved &&
                draft.draft?.capabilities.submit === true));

    const reset = useCallback(() => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setPending(null);
        setVisible(false);
        setStage('editing');
        setMessage(null);
        setErrors({});
        setLeave(null);
        setReplacement(null);
        setAutoApply(false);
        setDiscardProposal(false);
    }, []);
    useEffect(() => {
        if (pendingRef.current && pendingRef.current.actorId !== actorId)
            reset();
    }, [actorId, reset]);
    useEffect(() => {
        const nextId = recoveryTicket?.id ?? null;
        if (
            previousRecoveryId.current !== null &&
            previousRecoveryId.current !== nextId
        )
            reset();
        previousRecoveryId.current = nextId;
    }, [recoveryTicket?.id, reset]);
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );
    useEffect(() => {
        if (!message && !Object.keys(errors).length) return;
        const focus = window.setTimeout(() => alertRef.current?.focus(), 0);
        return () => window.clearTimeout(focus);
    }, [message, errors]);

    // A newer draft save must not invalidate an unconfirmed business command.
    const saveDraft = draft.save;
    useEffect(() => {
        if (
            !draftsEnabled ||
            !current ||
            (!editable && stage !== 'reason') ||
            draft.state !== 'ready' ||
            draftBusy ||
            draft.memoryBlocked ||
            draftSaved ||
            Object.keys(draft.errors).length
        )
            return;
        const timer = window.setTimeout(() => void saveDraft(snapshot), 800);
        return () => window.clearTimeout(timer);
    }, [
        draftsEnabled,
        current,
        editable,
        stage,
        draft.state,
        draftBusy,
        draft.memoryBlocked,
        draftSaved,
        draft.errors,
        saveDraft,
        snapshot,
    ]);

    useEffect(() => {
        if (!current || stage === 'access') return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const stop = router.on('before', (event) => {
            if (
                event.detail.visit.method !== 'get' ||
                approvedNavigation.current
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave({
                navigation: true,
                run: () => {
                    approvedNavigation.current = true;
                    reset();
                    router.visit(visit.url, visit);
                },
            });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            stop();
        };
    }, [current, stage, reset]);

    const send = async (proposal: PendingChange) => {
        if (
            request.current ||
            proposal.actorId !== currentActor.current ||
            !positive(proposal.actorId) ||
            draft.memoryBlocked
        )
            return;
        const exactSnapshot: ItDraftSnapshot = {
            fields: proposal.changes,
            step_index: 0,
            base_ticket_version: proposal.version,
        };
        const reference: ItDraftCommitReference | null = draftsEnabled
            ? draft.submissionReference(exactSnapshot)
            : null;
        if (draftsEnabled && !reference) {
            setVisible(true);
            setMessage(
                'Save and review this exact draft before applying its changes.',
            );
            return;
        }
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        const same = () =>
            epoch.current === token &&
            currentActor.current === proposal.actorId;
        setStage('sending');
        setMessage(null);
        setErrors({});
        try {
            const response = await axios.patch(
                `/it/tickets/${proposal.id}`,
                {
                    ...structuredClone(proposal.changes),
                    expected_version: proposal.version,
                    actor_user_id: proposal.actorId,
                    ...(reference ?? {}),
                },
                {
                    headers: { Accept: 'application/json' },
                    timeout: 30000,
                    signal: controller.signal,
                },
            );
            if (!same()) return;
            const body: unknown = response.data;
            if (
                record(body) &&
                record(body.data) &&
                positive(body.data.viewer_user_id) &&
                body.data.viewer_user_id !== proposal.actorId
            ) {
                deny();
                return;
            }
            if (
                response.status !== 200 ||
                !record(body) ||
                body.status !== 'committed' ||
                !record(body.data) ||
                body.data.id !== proposal.id ||
                body.data.viewer_user_id !== proposal.actorId ||
                !positive(body.data.lock_version) ||
                body.data.lock_version < proposal.version
            )
                throw new Error('Unconfirmed ticket acknowledgement');
            if (
                reference &&
                !draft.acknowledgeConsumed(body.data.draft, exactSnapshot)
            )
                throw new Error('Unconfirmed draft consumption');
            if (!reference && body.data.draft !== undefined)
                throw new Error('Unexpected draft acknowledgement');
            const version = body.data.lock_version;
            draft.clearOwnedBrowserWork();
            approvedNavigation.current = true;
            reset();
            toast.success('Ticket changes saved.');
            if (committed.current) committed.current(proposal.id, version);
            else router.reload({ preserveScroll: true });
        } catch (error) {
            if (!same()) return;
            const response = axios.isAxiosError(error)
                ? error.response
                : undefined;
            const body = record(response?.data) ? response.data : {};
            setVisible(true);
            if ([403, 404].includes(response?.status ?? 0)) {
                deny();
                return;
            }
            if ([401, 419].includes(response?.status ?? 0)) {
                setStage('session');
                setMessage(
                    'Sign in again, then review this ticket before applying the retained proposal.',
                );
            } else {
                const fieldErrors: Record<string, string> = {};
                if (record(body.errors))
                    for (const [key, value] of Object.entries(body.errors)) {
                        const first = Array.isArray(value) ? value[0] : value;
                        if (typeof first === 'string' && first.length <= 2000)
                            fieldErrors[key] = first;
                    }
                setErrors(fieldErrors);
                if (response?.status === 422)
                    setSettledOperation((value) => value + 1);
                setStage('unknown');
                setMessage(
                    response?.status === 422
                        ? 'The changes were rejected. Review the current ticket and correct the proposal before applying it again.'
                        : response?.status === 409
                          ? 'The ticket or saved draft changed. Review the current ticket before applying your retained changes.'
                          : 'The result is unconfirmed. The request may have finished. Review the current ticket before applying anything again.',
                );
            }
        } finally {
            if (same()) request.current = null;
        }
    };
    const begin = (proposal: PendingChange) => {
        approvedNavigation.current = false;
        setPending(proposal);
        setErrors({});
        setMessage(null);
        setStage(needsReason(proposal.changes) ? 'reason' : 'editing');
        setVisible(true);
        setAutoApply(!needsReason(proposal.changes) && !draftsEnabled);
    };
    // Wait for the new record scope to render before deciding whether an
    // earlier browser proposal requires explicit recovery instead of a write.
    useEffect(() => {
        if (!autoApply || !current || draftsEnabled) return;
        setAutoApply(false);
        if (!draft.memoryBlocked) void send(current);
        // The frozen proposal and its scope trigger this once. The transport
        // itself reads the current render's memory gate and original actor.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoApply, current, draftsEnabled, draft.memoryBlocked]);
    const submit = (
        id: number,
        version: number,
        changes: TicketPatch,
        descriptions: Record<string, string> = {},
    ) => {
        if (
            !positive(actorId) ||
            !positive(id) ||
            !positive(version) ||
            request.current
        )
            return false;
        const proposal = {
            actorId,
            id,
            version,
            changes: structuredClone(changes),
            descriptions: { ...descriptions },
        };
        if (pendingRef.current) setReplacement(proposal);
        else begin(proposal);
        return true;
    };
    const close = () => {
        if (!current) {
            reset();
            return;
        }
        setLeave({
            navigation: false,
            run: () => {
                if (request.current) {
                    ++epoch.current;
                    request.current.abort();
                    request.current = null;
                    setStage('unknown');
                    setMessage(
                        'The wait stopped. The request may still complete; review the current ticket before reapplying.',
                    );
                }
                setVisible(false);
            },
        });
    };
    const descriptions = Object.entries(current?.changes ?? {}).map(
        ([field, value]) => ({
            label: fieldLabels[field] ?? field.replaceAll('_', ' '),
            value: current?.descriptions[field] ?? valueLabel(value),
        }),
    );

    const resumeProposal = (restored: ItDraftSnapshot, unknown = false) => {
        if (
            !positive(actorId) ||
            !positive(context.ticketId) ||
            !positive(restored.base_ticket_version)
        )
            return;
        approvedNavigation.current = false;
        setPending({
            actorId,
            id: context.ticketId,
            version: restored.base_ticket_version,
            changes: restored.fields as TicketPatch,
            descriptions: {},
        });
        setAutoApply(false);
        setVisible(true);
        setStage(
            unknown
                ? 'unknown'
                : needsReason(restored.fields as TicketPatch)
                  ? 'reason'
                  : 'editing',
        );
        setErrors({});
        setMessage(
            unknown
                ? 'The earlier submission is unconfirmed. Review the current ticket before applying this retained proposal.'
                : 'Browser work restored with its original ticket version. Review the proposal before applying it.',
        );
    };
    const recoveryControls = (
        <TicketDraftRecovery
            draft={draft}
            snapshot={snapshot}
            hasLocalChanges={current !== null}
            onResume={(saved) =>
                resumeProposal({
                    ...saved.payload,
                    base_ticket_version: saved.draft.base_ticket_version,
                })
            }
            onResumeMemory={(restored) =>
                resumeProposal(
                    restored.snapshot,
                    restored.canonicalOutcomeUnknown,
                )
            }
            onDiscarded={() =>
                setMessage(
                    'Saved draft discarded. Any local proposal is retained. Start a new draft before applying it.',
                )
            }
            onStartNew={() =>
                setMessage(
                    'New draft started. Save the retained proposal before applying it.',
                )
            }
            renderReview={(saved) =>
                !concealed && (
                    <dl className="space-y-2">
                        {Object.entries(saved.payload.fields).map(
                            ([field, value]) => (
                                <div key={field}>
                                    <dt className="font-medium">
                                        {fieldLabels[field] ??
                                            field.replaceAll('_', ' ')}
                                    </dt>
                                    <dd className="break-words">
                                        {valueLabel(value)}
                                    </dd>
                                </div>
                            ),
                        )}
                    </dl>
                )
            }
        />
    );

    return {
        busy: processing || (visible && current !== null),
        hasPending: current !== null,
        submit,
        recovery: (
            <>
                {!current &&
                    positive(context.ticketId) &&
                    !concealed &&
                    recoveryControls}
                {!visible && current && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setVisible(true)}
                    >
                        Review retained ticket changes
                    </Button>
                )}
                <TicketTriageReasonDialog
                    open={
                        visible &&
                        current !== null &&
                        stage === 'reason' &&
                        !concealed
                    }
                    descriptions={descriptions.filter(
                        ({ label }) =>
                            label !== fieldLabels.priority_reason &&
                            label !== fieldLabels.routing_reason,
                    )}
                    reason={String(
                        current?.changes.priority_reason ??
                            current?.changes.routing_reason ??
                            '',
                    )}
                    onReasonChange={(reason) => {
                        if (!current) return;
                        const changes = { ...current.changes };
                        if (
                            'priority' in changes ||
                            changes.release_priority_override === true
                        )
                            changes.priority_reason = reason;
                        if (routingFields.some((field) => field in changes))
                            changes.routing_reason = reason;
                        setPending({ ...current, changes });
                    }}
                    onCancel={() => {
                        draft.clearOwnedBrowserWork();
                        reset();
                    }}
                    onConfirm={(reason) => {
                        if (!current) return;
                        const changes = { ...current.changes };
                        if (
                            'priority' in changes ||
                            changes.release_priority_override === true
                        )
                            changes.priority_reason = reason;
                        if (routingFields.some((field) => field in changes))
                            changes.routing_reason = reason;
                        const proposal = { ...current, changes };
                        setPending(proposal);
                        setStage('editing');
                        if (!draftsEnabled) void send(proposal);
                    }}
                />
                <Dialog
                    open={
                        visible &&
                        (stage !== 'reason' || concealed) &&
                        (current !== null || stage === 'access')
                    }
                    onOpenChange={(next) => !next && close()}
                >
                    <DialogContent
                        className="max-h-[90vh] min-w-0 overflow-y-auto"
                        style={{
                            maxWidth: 'min(92vw, 720px)',
                            width: 'min(92vw, 720px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Review ticket changes</DialogTitle>
                            <DialogDescription>
                                Your proposal stays here until its save is
                                confirmed or you deliberately replace it.
                            </DialogDescription>
                        </DialogHeader>
                        {(message || Object.keys(errors).length > 0) && (
                            <div
                                ref={alertRef}
                                tabIndex={-1}
                                role="alert"
                                className={cn(
                                    'min-w-0 space-y-2 text-sm',
                                    Object.keys(errors).length > 0 ||
                                        stage === 'access'
                                        ? 'text-status-critical'
                                        : stage === 'unknown' ||
                                            stage === 'session'
                                          ? 'text-status-warning'
                                          : 'text-muted-foreground',
                                )}
                            >
                                <p>{message}</p>
                                {Object.entries(errors).map(([field, text]) => (
                                    <p key={field}>{text}</p>
                                ))}
                            </div>
                        )}
                        {current && !concealed && (
                            <dl className="min-w-0 space-y-2 text-sm">
                                {descriptions.map(({ label, value }) => (
                                    <div key={label}>
                                        <dt className="font-medium">{label}</dt>
                                        <dd className="break-words text-muted-foreground">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {stage === 'session' && (
                            <Button asChild variant="outline">
                                <a
                                    href="/login"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Sign in again
                                </a>
                            </Button>
                        )}
                        {current && !processing && (
                            <TicketVersionConflict
                                actorId={current.actorId}
                                ticketId={current.id}
                                error={
                                    !editable
                                        ? 'Review the current ticket before applying the retained proposal.'
                                        : draft.browserBlocker !== null ||
                                            (draft.current ?? draft.draft)
                                                ?.blocker?.code ===
                                                'ticket_changed'
                                          ? 'This draft was based on an older ticket. Review the current ticket before applying it.'
                                          : undefined
                                }
                                onAccessLost={deny}
                                onReviewed={(version) => {
                                    if (!current) return;
                                    setPending({ ...current, version });
                                    draft.acknowledgeReviewedBrowserWork(
                                        version,
                                    );
                                    setStage('reviewed');
                                    setErrors({});
                                    setMessage(
                                        'Current ticket reviewed. Check your retained proposal, then apply it explicitly.',
                                    );
                                }}
                            />
                        )}
                        {current &&
                            stage !== 'access' &&
                            stage !== 'session' &&
                            (editable || stage === 'reason') &&
                            recoveryControls}
                        <DialogFooter className="flex-wrap sm:flex-wrap">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={close}
                            >
                                {current ? 'Return to ticket' : 'Close'}
                            </Button>
                            {current &&
                                !concealed &&
                                editable &&
                                !processing &&
                                !draft.busy &&
                                !draft.browserOutcomeUnknown &&
                                draft.state !== 'outcome_unknown' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setDiscardProposal(true)}
                                    >
                                        Discard local proposal
                                    </Button>
                                )}
                            {current &&
                                !concealed &&
                                editable &&
                                !processing &&
                                ('priority' in current.changes ||
                                    routingFields.some(
                                        (field) => field in current.changes,
                                    )) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setStage('reason')}
                                    >
                                        Edit explanation
                                    </Button>
                                )}
                            {current && (
                                <Button
                                    type="button"
                                    disabled={!canApply}
                                    onClick={() => void send(current)}
                                >
                                    {processing
                                        ? 'Saving…'
                                        : 'Apply my changes'}
                                </Button>
                            )}
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <ConfirmDialog
                    open={leave !== null}
                    onClose={() => setLeave(null)}
                    onConfirm={() => leave?.run()}
                    title={
                        leave?.navigation
                            ? 'Leave these proposed changes?'
                            : 'Return with changes pending?'
                    }
                    description={
                        leave?.navigation
                            ? `${stage === 'unknown' || processing ? 'The request may already have finished. ' : ''}${draftSaved ? 'The saved draft can be recovered later. ' : 'Unsaved proposed values remain in this open application for explicit recovery after checking access. A full reload or closing the application loses unsaved browser work. '}Leaving does not cancel a server operation.`
                            : 'The proposal will stay on this page for review. A request already sent may still finish; closing this panel does not cancel it.'
                    }
                    confirmText={
                        leave?.navigation
                            ? 'Leave page'
                            : 'Keep proposal and return'
                    }
                />
                <ConfirmDialog
                    open={discardProposal}
                    onClose={() => setDiscardProposal(false)}
                    title="Discard this local proposal?"
                    description="This removes the proposed values from this editor and its browser recovery copy. It does not change the ticket. Any saved server draft remains available through saved draft recovery."
                    confirmText="Discard local proposal"
                    onConfirm={() => {
                        draft.clearOwnedBrowserWork();
                        reset();
                    }}
                />
                <ConfirmDialog
                    open={replacement !== null}
                    onClose={() => setReplacement(null)}
                    title="Replace the retained proposal?"
                    description="This replaces the local proposed values. Any earlier server request may already have finished; review its ticket before repeating it. Saved server drafts remain recoverable."
                    confirmText="Replace proposal"
                    onConfirm={() => {
                        if (replacement) {
                            const next = replacement;
                            draft.clearOwnedBrowserWork();
                            reset();
                            begin(next);
                        }
                    }}
                />
            </>
        ),
    };
}

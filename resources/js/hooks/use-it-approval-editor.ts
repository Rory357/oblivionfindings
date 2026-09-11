import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    canSubmitApproval,
    readItApprovalReview,
    type ItApprovalReview,
    type ItApprovalWork,
} from './it-approval-work';
import {
    approvalFieldNames,
    freezeItApprovalIntent,
    type ItApprovalCommitted,
    type ItApprovalFields,
    type ItApprovalOperation,
} from './it-ticket-approval-contract';
import { draftRecord, type ItDraftSnapshot } from './it-ticket-draft-contract';
import { useItTicketApprovalCommand } from './use-it-ticket-approval-command';
import { useItTicketDraftMemory } from './use-it-ticket-draft-memory';

/** Approval host of the canonical command and shared, bounded document-memory recovery store. */
export function useItApprovalEditor({
    actorId,
    ticketId,
    approvalId,
    operation,
    version,
    work,
    initialDecision,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number;
    ticketId: number;
    approvalId: number | null;
    operation: ItApprovalOperation;
    version: number;
    work: ItApprovalWork;
    initialDecision?: 'approve' | 'reject';
    onCommitted: (result: ItApprovalCommitted) => void;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}) {
    const scope = `${actorId}:${ticketId}:${operation}:${approvalId}`;
    const [originalScope] = useState(scope);
    const sameScope = scope === originalScope;
    const liveScope = useRef(scope);
    liveScope.current = scope;
    const callbacks = useRef({ onAccessLost, onSessionExpired });
    callbacks.current = { onAccessLost, onSessionExpired };
    const [baseline, setBaseline] = useState<ItApprovalFields>(() =>
        operation === 'request'
            ? {
                  reason: '',
                  primary_approver_user_id: null,
                  cover_approver_user_id: null,
                  expires_at: null,
                  remind_at: null,
              }
            : operation === 'decide'
              ? { decision: initialDecision ?? 'approve', reason: '' }
              : { reason: '' },
    );
    const [fields, setFields] = useState<ItApprovalFields>(() =>
        structuredClone(baseline),
    );
    const [baseVersion, setBaseVersion] = useState(version);
    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [blocker, setBlocker] = useState<string | null>(null);
    const [separate, setSeparate] = useState(false);
    const [retainedAfterCommit, setRetainedAfterCommit] = useState(false);
    const [review, setReview] = useState<ItApprovalReview | null>(null);
    const [adopted, setAdopted] = useState<ItApprovalReview | null>(null);
    const [reviewState, setReviewState] = useState<
        'idle' | 'loading' | 'failed' | 'session' | 'unconfirmed'
    >('idle');
    const [reviewMessage, setReviewMessage] = useState<string | null>(null);
    const active = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const memoryRef = useRef<ReturnType<typeof useItTicketDraftMemory> | null>(
        null,
    );
    const stopReview = useCallback(() => {
        ++epoch.current;
        active.current?.abort();
        active.current = null;
    }, []);
    const deny = useCallback(() => {
        stopReview();
        memoryRef.current?.clearCurrentScope();
        setFields({ reason: '' });
        setBaseline({ reason: '' });
        setReview(null);
        setAdopted(null);
        setLocalErrors({});
        callbacks.current.onAccessLost();
    }, [stopReview]);
    const dirty = JSON.stringify(fields) !== JSON.stringify(baseline);
    const command = useItTicketApprovalCommand({
        actorId,
        ticketId,
        approvalId,
        operation,
        onAccessLost: deny,
        onSessionExpired,
        onCommitted: (result, original) => {
            const current = original
                ? freezeItApprovalIntent(original, fields)
                : null;
            if (
                original &&
                original.expectedVersion === baseVersion &&
                JSON.stringify(current?.fields) ===
                    JSON.stringify(original.fields)
            ) {
                memoryRef.current?.clearOwnedWork();
                setBaseline(structuredClone(fields));
            } else if (dirty) setRetainedAfterCommit(true);
            onCommitted(result);
        },
    });
    const concealCommand = command.denyCurrentAccess;
    const denyAccess = useCallback(() => {
        concealCommand();
        deny();
    }, [concealCommand, deny]);
    const snapshot: ItDraftSnapshot = {
        fields,
        step_index: stepIndex,
        base_ticket_version: baseVersion,
    };
    const memory = useItTicketDraftMemory({
        enabled: sameScope && command.stage !== 'access',
        persistenceEnabled: false,
        actorId,
        context: { purpose: 'approval_work', ticketId, operation, approvalId },
        draft: null,
        workingSnapshot: snapshot,
        workingDirty: dirty,
        pendingApproval: command.pendingIntent ?? undefined,
        outcomeUnknown: command.outcomeUnknown,
        settledOperationToken: command.settledOperationToken,
        acceptedFields: approvalFieldNames[operation],
        acceptsPendingApproval: command.canRestoreIntent,
        acceptSelectedFiles: false,
        canRecover: !command.busy && reviewState !== 'loading',
        onAccessLost: denyAccess,
    });
    useLayoutEffect(() => {
        memoryRef.current = memory;
    }, [memory]);
    useEffect(() => () => stopReview(), [stopReview]);
    useEffect(() => {
        if (!sameScope) denyAccess();
    }, [sameScope, denyAccess]);
    useEffect(() => {
        if (memory.failure === 'session_expired')
            callbacks.current.onSessionExpired?.();
    }, [memory.failure]);
    useEffect(() => {
        if (
            !sameScope ||
            command.stage === 'access' ||
            (!dirty && !command.references.length && !memory.notices.length)
        )
            return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [
        sameScope,
        dirty,
        command.stage,
        command.references.length,
        memory.notices.length,
    ]);
    const concealed =
        !sameScope ||
        command.concealed ||
        ['session', 'unconfirmed'].includes(reviewState) ||
        memory.failure === 'access_denied' ||
        memory.failure === 'session_expired';
    const currentWork =
        adopted && adopted.version >= version ? adopted.work : work;
    const allowed = canSubmitApproval(currentWork, operation, approvalId);
    const stale = Math.max(version, adopted?.version ?? 0) > baseVersion;
    const recoveredWaiting = memory.notices.length > 0 && !separate;
    const busy = command.busy || memory.busy || reviewState === 'loading';
    const canEdit =
        sameScope &&
        allowed &&
        command.canEdit &&
        !concealed &&
        !busy &&
        !blocker &&
        !recoveredWaiting;
    const setField = <K extends keyof ItApprovalFields>(
        key: K,
        value: ItApprovalFields[K],
    ) => {
        if (!canEdit) return;
        setFields((previous) => ({ ...previous, [key]: value }));
        command.clearFieldError(key);
        setLocalErrors((previous) =>
            Object.fromEntries(
                Object.entries(previous).filter(([name]) => name !== key),
            ),
        );
    };
    const validate = () => {
        const errors: Record<string, string> = {};
        if (operation === 'request' && !fields.primary_approver_user_id)
            errors.primary_approver_user_id =
                'Choose the person responsible for this decision.';
        if (
            operation === 'request' &&
            fields.primary_approver_user_id === fields.cover_approver_user_id &&
            fields.primary_approver_user_id
        )
            errors.cover_approver_user_id =
                'Choose a different person for absence cover.';
        if (
            (operation === 'withdraw' || fields.decision === 'reject') &&
            !fields.reason?.trim()
        )
            errors.reason = 'Explain this decision so the requester can act.';
        if ((fields.reason?.length ?? 0) > 1000)
            errors.reason = 'Use no more than 1,000 characters.';
        for (const key of ['expires_at', 'remind_at'] as const) {
            if (
                fields[key] &&
                (!Number.isFinite(Date.parse(fields[key]!)) ||
                    Date.parse(fields[key]!) <= Date.now())
            )
                errors[key] = 'Choose a future date and time.';
        }
        if (
            fields.remind_at &&
            fields.expires_at &&
            Date.parse(fields.remind_at) >= Date.parse(fields.expires_at)
        )
            errors.remind_at = 'Set the reminder before the deadline.';
        setLocalErrors(errors);
        return errors;
    };
    const resume = async (id: string) => {
        const restored = await memory.resume(id);
        if (
            !restored ||
            restored.candidate.context.purpose !== 'approval_work' ||
            liveScope.current !== originalScope
        )
            return;
        if (
            restored.candidate.pendingApproval &&
            !command.restoreAuthorizedIntent(restored.candidate.pendingApproval)
        )
            return;
        setFields(restored.candidate.snapshot.fields as ItApprovalFields);
        setBaseVersion(
            restored.candidate.snapshot.base_ticket_version ?? baseVersion,
        );
        setStepIndex(Math.min(2, restored.candidate.snapshot.step_index));
        setSeparate(true);
        setLocalErrors({});
        setBlocker(
            restored.localAuthorization?.capabilities.submit === false
                ? (restored.localAuthorization.blocker?.message ??
                      'Review the current approval before using this proposal.')
                : null,
        );
    };
    const reviewCurrent = async () => {
        if (busy || !sameScope || command.stage === 'access') return;
        stopReview();
        const controller = new AbortController();
        active.current = controller;
        const token = epoch.current;
        const valid = () =>
            token === epoch.current && liveScope.current === originalScope;
        setReview(null);
        setReviewState('loading');
        setReviewMessage(null);
        try {
            const response = await axios.get(`/it/tickets/${ticketId}`, {
                signal: controller.signal,
                timeout: 20000,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!valid()) return;
            if (
                draftRecord(response.data) &&
                ((typeof response.data.viewer_user_id === 'number' &&
                    response.data.viewer_user_id !== actorId) ||
                    (draftRecord(response.data.can) &&
                        response.data.can.manage === false))
            ) {
                denyAccess();
                return;
            }
            const fresh =
                response.status === 200
                    ? readItApprovalReview(response.data, actorId, ticketId)
                    : null;
            if (
                !fresh ||
                !command.confirmCurrentAccess({
                    actorId,
                    ticketId,
                    approvalId,
                    operation,
                    currentTicketVersion: fresh.version,
                })
            ) {
                setReviewState('unconfirmed');
                setReviewMessage(
                    'Current access could not be confirmed. Your proposal is retained; retry the review.',
                );
                return;
            }
            setReview(fresh);
            setReviewState('idle');
            setReviewMessage(
                'Compare the current approval with your proposal. Using this version does not save anything.',
            );
        } catch (error) {
            if (!valid()) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 403 || status === 404) denyAccess();
            else if (status === 401 || status === 419) {
                setReviewState('session');
                setReviewMessage(
                    'Sign in with the same account, then check current access.',
                );
                callbacks.current.onSessionExpired?.();
            } else {
                setReviewState('failed');
                setReviewMessage(
                    'The current approval could not be loaded. Your proposal is retained. Retry the review.',
                );
            }
        } finally {
            if (valid()) active.current = null;
        }
    };
    const adoptReview = () => {
        if (
            !review ||
            busy ||
            command.outcomeUnknown ||
            command.references.length ||
            !canSubmitApproval(review.work, operation, approvalId) ||
            !command.reset(review.version)
        )
            return false;
        setBaseVersion(review.version);
        setAdopted(review);
        setReview(null);
        setReviewMessage(null);
        setBlocker(null);
        setLocalErrors({});
        return true;
    };
    const cancelWait = () => {
        if (reviewState === 'loading') {
            stopReview();
            setReviewState('failed');
            setReviewMessage('Stopped checking. No approval was changed.');
        } else if (memory.busy) memory.cancel();
        else command.stopWaiting();
    };
    const keepForClose = () => {
        if (memory.ensureLatestRetained().status === 'blocked') return false;
        cancelWait();
        return true;
    };
    const discardOwned = () => {
        if (busy || command.outcomeUnknown || command.references.length)
            return false;
        memory.clearOwnedWork();
        setFields(structuredClone(baseline));
        setLocalErrors({});
        return true;
    };
    return {
        fields,
        setField,
        baseVersion,
        stepIndex,
        setStepIndex,
        dirty,
        canEdit,
        concealed,
        busy,
        command,
        memory,
        resume,
        review,
        reviewState,
        reviewMessage,
        reviewCurrent,
        adoptReview,
        cancelWait,
        keepForClose,
        discardOwned,
        currentWork,
        stale,
        blocker,
        recoveredWaiting,
        retainedAfterCommit,
        startSeparateDraft: () => setSeparate(true),
        validate,
        errors: { ...command.errors, ...localErrors },
        submit: () =>
            canEdit &&
            !stale &&
            !Object.keys(validate()).length &&
            command.submit(fields, baseVersion),
    };
}

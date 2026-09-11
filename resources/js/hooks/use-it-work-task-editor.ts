import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    draftSnapshotKey,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import {
    taskFieldNames,
    type ItWorkTaskCommitted,
    type ItWorkTaskFields,
    type ItWorkTaskOperation,
    type ItWorkTaskRecord,
} from './it-work-task-command';
import { useItTicketDraftMemory } from './use-it-ticket-draft-memory';
import { useItWorkTaskCommand } from './use-it-work-task-command';

export function fieldsFromWorkTask(task: ItWorkTaskRecord): ItWorkTaskFields {
    return {
        title: task.title,
        description: task.description,
        status: task.status === 'completed' ? 'pending' : task.status,
        team_id: task.team?.id ?? null,
        assigned_to_user_id: task.assignee?.id ?? null,
        due_at: task.due_at,
        is_required: task.is_required,
        evidence_required: task.evidence_required,
        dependency_ids: task.dependencies.map(({ id }) => id),
        approval_id: task.approval?.id ?? null,
    };
}
export const initialWorkTaskFields = (
    operation: ItWorkTaskOperation,
    task: ItWorkTaskRecord | null,
): ItWorkTaskFields =>
    operation === 'update' && task
        ? fieldsFromWorkTask(task)
        : operation === 'create'
          ? {
                title: '',
                description: null,
                team_id: null,
                assigned_to_user_id: null,
                due_at: null,
                is_required: true,
                evidence_required: false,
                dependency_ids: [],
                approval_id: null,
            }
          : operation === 'complete'
            ? { completion_note: null, evidence: [] }
            : operation === 'reopen'
              ? { reason: '' }
              : { ordered_ids: [] };
export function changedWorkTaskFields(
    base: ItWorkTaskFields,
    current: ItWorkTaskFields,
): ItWorkTaskFields {
    return Object.fromEntries(
        Object.entries(current).filter(
            ([key, value]) =>
                JSON.stringify(value) !==
                JSON.stringify(base[key as keyof ItWorkTaskFields]),
        ),
    ) as ItWorkTaskFields;
}
const submittedFields = (
    operation: ItWorkTaskOperation,
    fields: ItWorkTaskFields,
): ItWorkTaskFields =>
    operation === 'complete'
        ? {
              ...fields,
              evidence:
                  fields.evidence
                      ?.map((entry) => entry.trim())
                      .filter(Boolean) ?? [],
          }
        : fields;

/** Task-specific host of the canonical command and existing RAM recovery inventory. */
export function useItWorkTaskEditor({
    actorId,
    ticketId,
    taskId,
    operation,
    version,
    initialFields,
    initialProposal,
    canManage,
    evidenceRequired = false,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number;
    ticketId: number;
    taskId: number | null;
    operation: ItWorkTaskOperation;
    version: number;
    initialFields: ItWorkTaskFields;
    initialProposal?: ItWorkTaskFields;
    canManage: boolean;
    evidenceRequired?: boolean;
    onCommitted: (result: ItWorkTaskCommitted) => void;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}) {
    const [originalScope] = useState(
        `${actorId}:${ticketId}:${operation}:${taskId}`,
    );
    const sameScope =
        originalScope === `${actorId}:${ticketId}:${operation}:${taskId}`;
    const [fields, setFields] = useState<ItWorkTaskFields>(() => ({
        ...structuredClone(initialFields),
        ...structuredClone(initialProposal ?? {}),
    }));
    const [baseVersion, setBaseVersion] = useState(version);
    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [restoredBlocker, setRestoredBlocker] = useState<string | null>(null);
    const [separateDraft, setSeparateDraft] = useState(false);
    const [retainedAfterCommit, setRetainedAfterCommit] = useState(false);
    const [baseline, setBaseline] = useState(() =>
        structuredClone(initialFields),
    );
    const memoryRef = useRef<ReturnType<typeof useItTicketDraftMemory> | null>(
        null,
    );
    const deniedCallback = useRef(onAccessLost);
    const sessionCallback = useRef(onSessionExpired);
    useLayoutEffect(() => {
        deniedCallback.current = onAccessLost;
        sessionCallback.current = onSessionExpired;
    }, [onAccessLost, onSessionExpired]);
    const onDenied = useCallback(() => {
        memoryRef.current?.clearCurrentScope();
        setFields({});
        setBaseline({});
        setLocalErrors({});
        deniedCallback.current?.();
    }, []);
    const command = useItWorkTaskCommand({
        actorId,
        ticketId,
        operation,
        taskId,
        onAccessLost: onDenied,
        onSessionExpired,
        onCommitted: (result, original) => {
            // An old receipt can settle while this editor holds a different
            // unsent proposal. Only its exact submitted snapshot is consumed.
            if (
                original &&
                original.expectedVersion === baseVersion &&
                JSON.stringify(original.fields) ===
                    JSON.stringify(submittedFields(operation, payload))
            ) {
                memoryRef.current?.clearOwnedWork();
                setBaseline(structuredClone(fields));
            } else if (dirty) {
                setRetainedAfterCommit(true);
            }
            onCommitted(result);
        },
    });
    const payload =
        operation === 'update'
            ? changedWorkTaskFields(baseline, fields)
            : fields;
    const reasonRequired =
        operation === 'update' &&
        ((fields.status !== baseline.status &&
            (fields.status === 'cancelled' ||
                baseline.status === 'cancelled')) ||
            fields.is_required !== baseline.is_required ||
            JSON.stringify(
                [...(fields.dependency_ids ?? [])].sort((a, b) => a - b),
            ) !==
                JSON.stringify(
                    [...(baseline.dependency_ids ?? [])].sort((a, b) => a - b),
                ) ||
            (fields.approval_id ?? null) !== (baseline.approval_id ?? null));
    const dirty =
        operation === 'update'
            ? Object.keys(payload).length > 0
            : JSON.stringify(fields) !== JSON.stringify(baseline);
    const snapshot: ItDraftSnapshot = {
        fields: payload,
        step_index: stepIndex,
        base_ticket_version: baseVersion,
    };
    const memory = useItTicketDraftMemory({
        enabled: sameScope && command.stage !== 'access',
        persistenceEnabled: false,
        actorId,
        context: { purpose: 'task_work', ticketId, operation, taskId },
        draft: null,
        workingSnapshot: snapshot,
        workingDirty: dirty,
        pendingTask: command.pendingIntent ?? undefined,
        outcomeUnknown: command.outcomeUnknown,
        settledOperationToken: command.settledOperationToken,
        acceptedFields: taskFieldNames[operation],
        acceptsPendingTask: command.canRestoreIntent,
        acceptSelectedFiles: false,
        canRecover: !command.busy,
        onAccessLost: command.denyCurrentAccess,
    });
    useLayoutEffect(() => {
        memoryRef.current = memory;
    }, [memory]);
    useEffect(() => {
        if (memory.failure === 'session_expired') sessionCallback.current?.();
    }, [memory.failure]);
    const concealed =
        !sameScope ||
        command.concealed ||
        memory.failure === 'session_expired' ||
        memory.failure === 'access_denied';
    const recoveredWaiting = memory.notices.length > 0 && !separateDraft;
    const canEdit =
        sameScope &&
        canManage &&
        command.canEdit &&
        !concealed &&
        !memory.busy &&
        !restoredBlocker &&
        !recoveredWaiting;
    const denyCurrentAccess = command.denyCurrentAccess;
    useEffect(() => {
        if (sameScope) return;
        denyCurrentAccess();
    }, [sameScope, denyCurrentAccess]);
    useEffect(() => {
        if (
            !sameScope ||
            command.stage === 'access' ||
            (!dirty &&
                !command.outcomeUnknown &&
                !command.references.length &&
                !memory.notices.length)
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
        command.outcomeUnknown,
        command.references.length,
        memory.notices.length,
    ]);
    const setField = <K extends keyof ItWorkTaskFields>(
        key: K,
        value: ItWorkTaskFields[K],
    ) => {
        if (!canEdit) return;
        setFields((previous) => ({ ...previous, [key]: value }));
        command.clearFieldError(key);
        setLocalErrors((previous) =>
            Object.fromEntries(
                Object.entries(previous).filter(([field]) => field !== key),
            ),
        );
    };
    const resume = async (bufferId: string) => {
        const recovered = await memory.resume(bufferId);
        if (
            !recovered ||
            recovered.candidate.context.purpose !== 'task_work' ||
            !sameScope
        )
            return;
        if (
            recovered.candidate.pendingTask &&
            !command.restoreAuthorizedIntent(recovered.candidate.pendingTask)
        )
            return;
        const proposal = recovered.candidate.snapshot;
        if (operation === 'update')
            setFields({
                ...baseline,
                ...proposal.fields,
            } as ItWorkTaskFields);
        else setFields(proposal.fields as ItWorkTaskFields);
        setBaseVersion(proposal.base_ticket_version ?? baseVersion);
        setStepIndex(Math.min(3, proposal.step_index));
        setSeparateDraft(true);
        setRestoredBlocker(
            recovered.localAuthorization?.capabilities.submit === false
                ? (recovered.localAuthorization.blocker?.message ??
                      'Review the current task before applying this recovered proposal.')
                : null,
        );
        setLocalErrors({});
    };
    const adoptReview = () => {
        const reviewed = command.adoptReview();
        if (!reviewed) return null;
        const changes =
            operation === 'update'
                ? changedWorkTaskFields(baseline, fields)
                : fields;
        if (operation === 'update') {
            const task = reviewed.tasks.find(({ id }) => id === taskId);
            if (!task) return null;
            const fresh = fieldsFromWorkTask(task);
            setBaseline(structuredClone(fresh));
            setFields({ ...fresh, ...changes });
        }
        setBaseVersion(reviewed.version);
        setRestoredBlocker(null);
        setLocalErrors({});
        return reviewed;
    };
    const validate = (): Record<string, string> => {
        const errors: Record<string, string> = {};
        if (
            (operation === 'create' || operation === 'update') &&
            !fields.title?.trim()
        )
            errors.title = 'Enter a task title.';
        if (
            (operation === 'reopen' || reasonRequired) &&
            !fields.reason?.trim()
        )
            errors.reason =
                operation === 'reopen'
                    ? 'Explain why this task needs to be reopened.'
                    : 'Explain this change to the task requirements or lifecycle.';
        if (
            operation === 'update' &&
            baseline.status === 'cancelled' &&
            fields.status !== 'cancelled' &&
            fields.status !== 'pending'
        )
            errors.status =
                'Restore the cancelled task to Pending before starting it.';
        if (
            operation === 'update' &&
            fields.status === 'cancelled' &&
            fields.is_required
        )
            errors.is_required =
                'Required work cannot be cancelled. Review an explicit change to optional work first.';
        if (
            operation === 'complete' &&
            Array.isArray(fields.evidence) &&
            fields.evidence.length > 20
        )
            errors.evidence = 'Use no more than 20 evidence references.';
        if (
            operation === 'complete' &&
            evidenceRequired &&
            !fields.evidence?.some((entry) => entry.trim())
        )
            errors.evidence = 'Add evidence before completing this task.';
        if (
            operation === 'complete' &&
            fields.evidence?.some((entry) => entry.length > 2000)
        )
            errors.evidence =
                'Each evidence reference must be no more than 2,000 characters.';
        setLocalErrors(errors);
        return errors;
    };
    const submit = () => {
        if (!canEdit || Object.keys(validate()).length) return false;
        return command.submit(submittedFields(operation, payload), baseVersion);
    };
    const discardOwned = () => {
        if (
            command.outcomeUnknown ||
            command.references.length ||
            command.busy ||
            memory.busy
        )
            return false;
        memory.clearOwnedWork();
        setFields(structuredClone(baseline));
        setLocalErrors({});
        return true;
    };
    const keepForClose = () => {
        const retention = memory.ensureLatestRetained();
        if (retention.status === 'blocked') return false;
        command.cancelWait();
        if (memory.busy) memory.cancel();
        return true;
    };
    const errors = { ...command.errors, ...localErrors };
    for (const [key, message] of Object.entries(errors)) {
        const field = key.split('.')[0];
        if (!errors[field]) errors[field] = message;
    }
    return {
        fields,
        setField,
        setFields,
        baseVersion,
        stepIndex,
        setStepIndex,
        dirty,
        concealed,
        canEdit,
        command,
        memory,
        errors,
        validate,
        submit,
        resume,
        adoptReview,
        discardOwned,
        keepForClose,
        restoredBlocker,
        retainedAfterCommit,
        recoveredWaiting,
        startSeparateDraft: () => setSeparateDraft(true),
        snapshotKey: draftSnapshotKey(snapshot),
        reasonRequired,
        baseline,
        payload,
    };
}

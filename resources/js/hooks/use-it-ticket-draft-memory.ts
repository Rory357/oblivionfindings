import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import {
    draftRecord,
    draftScopeKey,
    draftSnapshotKey,
    readDraftAttachment,
    readDraftMetadata,
    readDraftPayload,
    validDraftContext,
    type ItDraftAttachment,
    type ItDraftContext,
    type ItDraftMetadata,
    type ItDraftResumed,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import {
    createItDraftMemoryStore,
    itDraftBoundScope,
    newItDraftMemoryUuid,
    validItDraftBoundScopes,
    type ItDraftBoundScope,
    type ItDraftMemoryCandidate,
    type ItLocalDraftAuthorization,
    type ItPersistedDraftMemoryCandidate,
} from './it-ticket-draft-memory';

// Reviewed local resource ceiling, not an operational retention policy.
const memory = createItDraftMemoryStore({
    maxEntries: 20,
    maxBytes: 64 * 1024 * 1024,
});
const owners = new Map<string, symbol>();
const listeners = new Set<() => void>();
let currentActor: number | undefined;
let revision = 0;
const publish = () => {
    ++revision;
    listeners.forEach((listener) => listener());
};
const subscribe = (listener: () => void) => {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
};
const getRevision = () => revision;
const serverRevision = () => 0;

/** Explicit application logout/reset boundary. This never changes a canonical draft. */
export function clearItTicketDraftMemory() {
    memory.clear();
    owners.clear();
    currentActor = undefined;
    publish();
}

/** Opaque same-document intake locators; the pending command marker takes precedence. */
export function itIntakeDraftMemoryRequestIds(
    actorId: number,
    purpose: 'requester_intake' | 'technician_intake',
): string[] {
    if (currentActor !== actorId) return [];
    return [
        ...new Set(
            memory
                .discoverRequests(actorId, purpose)
                .filter((notice) => !owners.has(notice.bufferId))
                .map((notice) => notice.requestUuid),
        ),
    ];
}

/** Metadata-only task recovery register, sharing the existing bounded inventory. */
export function useItWorkTaskMemoryNotices(
    actorId: number | undefined,
    ticketId: number,
) {
    const snapshot = useMemo(() => {
        const empty: ReturnType<typeof memory.discoverTaskWork> = [];
        let notices = empty;
        let observedRevision = -1;
        return {
            getSnapshot() {
                const nextRevision = getRevision();
                if (observedRevision !== nextRevision) {
                    notices =
                        actorId && currentActor === actorId
                            ? memory
                                  .discoverTaskWork(actorId, ticketId)
                                  .filter(
                                      (notice) => !owners.has(notice.bufferId),
                                  )
                            : [];
                    observedRevision = nextRevision;
                }
                return notices;
            },
            getServerSnapshot: () => empty,
        };
    }, [actorId, ticketId]);
    // Subscribe to the actual cached metadata snapshot. Reading mutable store
    // data separately during render allows the compiler to retain stale rows.
    return useSyncExternalStore(
        subscribe,
        snapshot.getSnapshot,
        snapshot.getServerSnapshot,
    );
}
export function purgeItWorkTaskMemory(actorId: number, ticketId: number) {
    for (const entry of memory.discoverTaskWork(actorId, ticketId)) {
        memory.purgeContext(actorId, entry.context);
        owners.delete(entry.bufferId);
    }
    publish();
}

export function useItApprovalMemoryNotices(
    actorId: number | undefined,
    ticketId: number,
) {
    const snapshot = useMemo(() => {
        const empty: ReturnType<typeof memory.discoverApprovalWork> = [];
        let notices = empty;
        let observedRevision = -1;
        return {
            getSnapshot() {
                const nextRevision = getRevision();
                if (observedRevision !== nextRevision) {
                    notices =
                        actorId && currentActor === actorId
                            ? memory
                                  .discoverApprovalWork(actorId, ticketId)
                                  .filter(
                                      (notice) => !owners.has(notice.bufferId),
                                  )
                            : [];
                    observedRevision = nextRevision;
                }
                return notices;
            },
            getServerSnapshot: () => empty,
        };
    }, [actorId, ticketId]);
    return useSyncExternalStore(
        subscribe,
        snapshot.getSnapshot,
        snapshot.getServerSnapshot,
    );
}

export function purgeItApprovalMemory(actorId: number, ticketId: number) {
    for (const entry of memory.discoverApprovalWork(actorId, ticketId)) {
        memory.purgeContext(actorId, entry.context);
        owners.delete(entry.bufferId);
    }
    publish();
}

interface Options {
    enabled: boolean;
    persistenceEnabled?: boolean;
    actorId?: number;
    context: ItDraftContext;
    draft: ItDraftMetadata | null;
    workingSnapshot?: ItDraftSnapshot;
    workingDirty?: boolean;
    workingFiles?: File[];
    acceptedFields?: readonly string[];
    acceptSelectedFiles?: boolean;
    acceptsSnapshot?: (snapshot: ItDraftSnapshot) => boolean;
    acceptsPendingTask?: (
        intent: NonNullable<ItDraftMemoryCandidate['pendingTask']>,
    ) => boolean;
    acceptsPendingApproval?: (
        intent: NonNullable<ItDraftMemoryCandidate['pendingApproval']>,
    ) => boolean;
    savedSnapshotKey?: string | null;
    pendingSave?: ItDraftMemoryCandidate['pendingSave'];
    pendingUpload?: ItDraftMemoryCandidate['pendingUpload'];
    pendingComment?: ItDraftMemoryCandidate['pendingComment'];
    pendingTask?: ItDraftMemoryCandidate['pendingTask'];
    pendingApproval?: ItDraftMemoryCandidate['pendingApproval'];
    outcomeUnknown: boolean;
    /** Advance only for a definitive response to the mounted frozen operation. */
    settledOperationToken?: number;
    explicitlyReviewedRevision?: number;
    canRecover?: boolean;
    timeoutMs?: number;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}
type Failure =
    | 'session_expired'
    | 'access_denied'
    | 'conflict'
    | 'failed'
    | null;
export type ItDraftRetentionResult =
    | { status: 'retained' | 'not_needed' }
    | {
          status: 'blocked';
          reason:
              | 'capacity'
              | 'bindings'
              | 'frozen'
              | 'scope'
              | 'handoff'
              | 'unavailable'
              | 'invalid';
          message: string;
      };

/** Continuous local handoff; fresh canonical authorization is required before disclosure. */
export function useItTicketDraftMemory(options: Options) {
    const scope = draftScopeKey(options.actorId, options.context);
    const latest = useRef(options);
    latest.current = options;
    const owner = useRef(Symbol('it-draft-form'));
    const observedActor = useRef<{ initialized: boolean; actorId?: number }>({
        initialized: false,
    });
    const owned = useRef<{
        id: string;
        scope: string;
        generation: string;
        kind: 'memory' | 'persisted';
        signature: string;
        unknown: boolean;
        settledOperationToken?: number;
        pendingFile?: File;
        pendingSave?: ItDraftMemoryCandidate['pendingSave'];
        selectedFiles?: File[];
        pendingComment?: ItDraftMemoryCandidate['pendingComment'];
        pendingTask?: ItDraftMemoryCandidate['pendingTask'];
        pendingApproval?: ItDraftMemoryCandidate['pendingApproval'];
    } | null>(null);
    const localContext = useRef<{
        scope: string;
        uuid: string;
        boundScopes: ItDraftBoundScope[];
    } | null>(null);
    // Keep a just-authorized command copy until its separate client accepts
    // it. A failed second access check must not turn a successful read into loss.
    const commentHandoff = useRef<{
        id: string;
        scope: string;
        requestUuid: string;
    } | null>(null);
    const explicitlyCleared = useRef<{
        scope: string;
        snapshotKey: string | null;
        files: File[];
    } | null>(null);
    const mounted = useRef(true);
    const epoch = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const scopeRef = useRef(scope);
    scopeRef.current = scope;
    const abortRequest = useCallback(() => {
        ++epoch.current;
        controller.current?.abort();
        controller.current = null;
    }, []);
    const [state, setState] = useState<{
        scope: string;
        warning: string | null;
        busy: boolean;
        failure: Failure;
    }>({ scope, warning: null, busy: false, failure: null });
    useSyncExternalStore(subscribe, getRevision, serverRevision);

    const warn = useCallback(
        (warning: string | null, failure: Failure = null) => {
            if (mounted.current)
                setState((current) =>
                    current.scope === scopeRef.current &&
                    current.warning === warning &&
                    current.failure === failure
                        ? current
                        : {
                              scope: scopeRef.current,
                              warning,
                              failure,
                              busy:
                                  current.scope === scopeRef.current &&
                                  current.busy,
                          },
                );
        },
        [],
    );
    const release = useCallback((discard: boolean) => {
        const held = owned.current;
        if (!held) return;
        owners.delete(held.id);
        if (discard)
            memory.discardBuffer(held.id, {
                actorId: latest.current.actorId!,
                context: latest.current.context,
            });
        owned.current = null;
        publish();
    }, []);
    const retainLatest = useCallback((): ItDraftRetentionResult => {
        const value = latest.current;
        if (
            !value.enabled ||
            !validDraftContext(value.actorId, value.context) ||
            value.actorId !== currentActor
        )
            return {
                status: 'blocked',
                reason: 'scope',
                message:
                    'Current browser recovery scope could not be confirmed. Keep this form open until current access is checked, or explicitly discard its unsent work.',
            };
        if (commentHandoff.current) {
            if (
                commentHandoff.current.scope !==
                draftScopeKey(value.actorId, value.context)
            )
                commentHandoff.current = null;
            else if (
                (value.pendingComment?.requestUuid ??
                    value.pendingTask?.requestUuid ??
                    value.pendingApproval?.requestUuid) !==
                commentHandoff.current.requestUuid
            )
                return {
                    status: 'blocked',
                    reason: 'handoff',
                    message:
                        'An earlier command is still being restored. Keep this form open until its original work is available.',
                };
        }
        const draft =
            value.draft && readDraftMetadata(value.draft, value.context);
        const snapshot = value.workingSnapshot ?? value.pendingSave?.snapshot;
        // A committed reply can already have consumed its saved draft. Recovery of
        // its exact command uses current ticket/audience authorization, not an active row.
        const persisted =
            value.persistenceEnabled !== false &&
            !!draft &&
            !value.pendingComment &&
            !value.pendingTask &&
            !value.pendingApproval &&
            !['task_work', 'approval_work'].includes(value.context.purpose);
        if (
            !snapshot ||
            (persisted &&
                (draft!.state !== 'active' || !draft!.capabilities.read))
        )
            return !snapshot &&
                !value.workingDirty &&
                !value.pendingSave &&
                !value.pendingUpload &&
                !value.pendingComment &&
                !value.pendingTask &&
                !value.pendingApproval &&
                !value.workingFiles?.length &&
                !value.outcomeUnknown
                ? { status: 'not_needed' }
                : {
                      status: 'blocked',
                      reason: 'unavailable',
                      message:
                          'The current draft cannot retain these changes yet. Keep this form open and review its saved state before leaving.',
                  };
        const snapshotKey = draftSnapshotKey(snapshot);
        if (explicitlyCleared.current) {
            const cleared = explicitlyCleared.current;
            if (
                cleared.scope === draftScopeKey(value.actorId, value.context) &&
                cleared.snapshotKey === snapshotKey &&
                !value.pendingSave &&
                !value.pendingUpload &&
                !value.pendingComment &&
                !value.pendingTask &&
                !value.pendingApproval &&
                !value.outcomeUnknown &&
                cleared.files.length === (value.workingFiles?.length ?? 0) &&
                cleared.files.every(
                    (file, index) => file === value.workingFiles?.[index],
                )
            )
                return { status: 'not_needed' };
            explicitlyCleared.current = null;
        }
        const needsRetention =
            value.workingDirty ||
            !!value.pendingSave ||
            !!value.pendingUpload ||
            !!value.pendingComment ||
            !!value.pendingTask ||
            !!value.pendingApproval ||
            !!value.workingFiles?.length ||
            value.outcomeUnknown ||
            (value.savedSnapshotKey != null &&
                value.savedSnapshotKey !== snapshotKey);
        if (
            !needsRetention ||
            (value.savedSnapshotKey === snapshotKey &&
                !value.pendingSave &&
                !value.pendingUpload &&
                !value.pendingComment &&
                !value.pendingTask &&
                !value.pendingApproval &&
                !value.workingFiles?.length &&
                !value.outcomeUnknown)
        ) {
            release(true);
            return { status: 'not_needed' };
        }
        const candidateScope = draftScopeKey(value.actorId, value.context);
        if (
            !localContext.current ||
            localContext.current.scope !== candidateScope
        )
            localContext.current = {
                scope: candidateScope,
                uuid: newItDraftMemoryUuid(),
                boundScopes: [],
            };
        const selection = itDraftBoundScope(snapshot);
        const knownBindings = localContext.current.boundScopes;
        if (
            Object.keys(selection).length &&
            !knownBindings.some(
                (scope) => JSON.stringify(scope) === JSON.stringify(selection),
            )
        ) {
            const next = [...knownBindings, selection];
            if (!validItDraftBoundScopes(next)) {
                const message =
                    'The browser recovery reference limit was reached. Keep this form open and save or explicitly discard its earlier work before leaving. Earlier privacy bindings have been retained.';
                warn(message);
                return { status: 'blocked', reason: 'bindings', message };
            }
            localContext.current.boundScopes = next;
        }
        const content = {
            actorId: value.actorId!,
            context: value.context,
            snapshot,
            outcomeUnknown: value.outcomeUnknown,
            boundScopes: localContext.current.boundScopes,
            ...(value.pendingComment
                ? { pendingComment: value.pendingComment }
                : {}),
            ...(value.pendingTask ? { pendingTask: value.pendingTask } : {}),
            ...(value.pendingApproval
                ? { pendingApproval: value.pendingApproval }
                : {}),
        };
        const candidate: ItDraftMemoryCandidate = persisted
            ? {
                  ...content,
                  draftUuid: draft!.draft_uuid,
                  revision: draft!.revision,
                  ...(value.pendingSave
                      ? { pendingSave: value.pendingSave }
                      : {}),
                  ...(value.pendingUpload
                      ? { pendingUpload: value.pendingUpload }
                      : {}),
              }
            : {
                  ...content,
                  kind: 'memory',
                  memoryUuid: localContext.current.uuid,
                  revision: 0,
                  ...(value.workingFiles?.length
                      ? { selectedFiles: [...value.workingFiles] }
                      : {}),
              };
        const generation =
            candidate.kind === 'memory'
                ? candidate.memoryUuid
                : candidate.draftUuid;
        const signature = JSON.stringify({
            scope: draftScopeKey(value.actorId, value.context),
            generation,
            revision: candidate.revision,
            snapshot,
            pendingSave: value.pendingSave,
            pendingComment: value.pendingComment,
            pendingTask: value.pendingTask,
            pendingApproval: value.pendingApproval,
            pendingUpload: value.pendingUpload && {
                uuid: value.pendingUpload.uploadUuid,
                revision: value.pendingUpload.revision,
                name: value.pendingUpload.file.name,
                size: value.pendingUpload.file.size,
                modified: value.pendingUpload.file.lastModified,
            },
            unknown: value.outcomeUnknown,
            boundScopes: candidate.boundScopes,
            selectedFiles: candidate.selectedFiles?.map((file) => ({
                name: file.name,
                size: file.size,
                modified: file.lastModified,
            })),
        });
        if (
            owned.current?.signature === signature &&
            owned.current?.pendingFile === value.pendingUpload?.file &&
            !owned.current?.pendingComment?.files.some(
                (file, index) => file !== value.pendingComment?.files[index],
            ) &&
            (owned.current?.selectedFiles?.length ?? 0) ===
                (candidate.selectedFiles?.length ?? 0) &&
            !owned.current?.selectedFiles?.some(
                (file, index) => file !== candidate.selectedFiles?.[index],
            ) &&
            memory
                .discover(value.actorId!, value.context)
                .some((notice) => notice.bufferId === owned.current?.id)
        )
            return { status: 'retained' };
        // Settle the current operation before changing storage kind/generation;
        // otherwise a released reply would remain as an abandoned frozen copy.
        // React can batch a successful save directly into a pending comment.
        // Its new uncertainty must not keep the acknowledged save frozen.
        const savedOwnedSnapshot =
            owned.current?.pendingSave &&
            draftSnapshotKey(owned.current.pendingSave.snapshot);
        const savedBeforeComment = !!(
            value.pendingComment?.draft &&
            owned.current?.kind === 'persisted' &&
            owned.current.scope === candidateScope &&
            owned.current.generation === draft?.draft_uuid &&
            owned.current.pendingSave &&
            !owned.current.pendingFile &&
            !owned.current.selectedFiles?.length &&
            !owned.current.pendingComment &&
            !owned.current.pendingTask &&
            !owned.current.pendingApproval &&
            draft.revision === owned.current.pendingSave.revision + 1 &&
            savedOwnedSnapshot === snapshotKey &&
            value.savedSnapshotKey === snapshotKey &&
            !value.workingFiles?.length &&
            value.pendingComment.draft.draft_uuid === draft.draft_uuid &&
            value.pendingComment.draft.draft_revision === draft.revision
        );
        if (
            owned.current?.unknown &&
            !value.pendingSave &&
            !value.pendingUpload &&
            !value.pendingTask &&
            !value.pendingApproval &&
            ((!value.outcomeUnknown && !value.pendingComment) ||
                savedBeforeComment) &&
            value.settledOperationToken !== undefined &&
            value.settledOperationToken !== owned.current.settledOperationToken
        )
            release(true);
        const initializingOwnedDraft = !!(
            owned.current &&
            owned.current.kind === 'memory' &&
            candidate.kind !== 'memory' &&
            owned.current.scope ===
                draftScopeKey(value.actorId, value.context) &&
            !owned.current.unknown
        );
        if (
            owned.current &&
            (owned.current.scope !==
                draftScopeKey(value.actorId, value.context) ||
                owned.current.generation !== generation) &&
            !initializingOwnedDraft
        )
            release(false);
        const result =
            initializingOwnedDraft && owned.current
                ? memory.initializeOwnedDraft(owned.current.id, candidate)
                : memory.retain(
                      candidate,
                      owned.current?.id ?? commentHandoff.current?.id,
                  );
        if (result.status !== 'stored') {
            const message =
                result.status === 'capacity'
                    ? 'Browser recovery memory is full. Keep this form open and save its draft, or deliberately discard older browser work before leaving. Your latest changes have not been retained for Back or Forward.'
                    : result.status === 'frozen'
                      ? 'An earlier draft operation is still unconfirmed. Check its result before replacing its recovery copy. Keep this form open.'
                      : 'Your latest changes could not be retained for browser recovery. Keep this form open and save or review the draft before leaving.';
            warn(message);
            return {
                status: 'blocked',
                reason:
                    result.status === 'capacity' || result.status === 'frozen'
                        ? result.status
                        : 'invalid',
                message,
            };
        }
        if (initializingOwnedDraft && owned.current)
            owners.delete(owned.current.id);
        owned.current = {
            id: result.notice.bufferId,
            scope: draftScopeKey(value.actorId, value.context),
            generation,
            kind: candidate.kind ?? 'persisted',
            signature,
            unknown: value.outcomeUnknown,
            settledOperationToken: value.settledOperationToken,
            pendingFile: value.pendingUpload?.file,
            pendingSave: value.pendingSave,
            selectedFiles: candidate.selectedFiles,
            pendingComment: value.pendingComment,
            pendingTask: value.pendingTask,
            pendingApproval: value.pendingApproval,
        };
        commentHandoff.current = null;
        owners.set(result.notice.bufferId, owner.current);
        publish();
        warn(null);
        return { status: 'retained' };
    }, [release, warn]);

    useLayoutEffect(() => {
        mounted.current = true;
        if (
            !observedActor.current.initialized ||
            observedActor.current.actorId !== options.actorId
        ) {
            observedActor.current = {
                initialized: true,
                actorId: options.actorId,
            };
            if (options.actorId !== currentActor) {
                memory.clear();
                owners.clear();
                currentActor = options.actorId;
                owned.current = null;
                publish();
            }
        }
        if (!options.enabled) {
            release(false);
            return;
        }
        retainLatest();
    });
    useEffect(() => {
        ++epoch.current;
        return abortRequest;
    }, [scope, options.enabled, abortRequest]);
    useEffect(
        () => () => {
            // Capture the last committed render without starting a network write.
            retainLatest();
            mounted.current = false;
            ++epoch.current;
            controller.current?.abort();
            release(false);
        },
        [release, retainLatest],
    );

    const resume = async (
        bufferId: string,
    ): Promise<{
        candidate: ItDraftMemoryCandidate;
        serverResumed: ItDraftResumed | null;
        localAuthorization?: ItLocalDraftAuthorization;
    } | null> => {
        const initial = latest.current;
        if (
            !initial.enabled ||
            initial.canRecover === false ||
            controller.current ||
            !validDraftContext(initial.actorId, initial.context) ||
            owners.has(bufferId)
        )
            return null;
        const binding = { actorId: initial.actorId!, context: initial.context };
        const originalScope = draftScopeKey(initial.actorId, initial.context);
        const request = new AbortController();
        controller.current = request;
        const token = ++epoch.current;
        const same = () =>
            mounted.current &&
            epoch.current === token &&
            scopeRef.current === originalScope &&
            currentActor === initial.actorId &&
            latest.current.enabled &&
            !request.signal.aborted;
        setState({
            scope: originalScope,
            warning: null,
            failure: null,
            busy: true,
        });
        let checkedUuid: string | null = null;
        let checkedRevision: number | null = null;
        let checkedCandidate: ItDraftMemoryCandidate | null = null;
        let lastFailure: Failure = null;
        const failure = (error: unknown) => {
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 403 || status === 404) {
                lastFailure = 'access_denied';
                memory.purgeContext(binding.actorId, binding.context);
                publish();
                warn(
                    'Your access changed. Browser draft recovery is unavailable and its local content has been removed.',
                    'access_denied',
                );
                initial.onAccessLost?.();
                return 'denied' as const;
            }
            if (status === 401 || status === 419) {
                lastFailure = 'session_expired';
                warn(
                    'Sign in with the same account before recovering browser work. Its content remains concealed.',
                    'session_expired',
                );
                initial.onSessionExpired?.();
                return 'session_expired' as const;
            }
            if (status === 409) {
                lastFailure = 'conflict';
                warn(
                    'The saved draft changed. Review that revision before restoring your retained browser changes.',
                    'conflict',
                );
                return 'conflict' as const;
            }
            lastFailure = 'failed';
            warn(
                'Browser recovery could not be confirmed. Your local copy is retained; try again after checking the connection.',
                'failed',
            );
            return 'failed' as const;
        };
        try {
            const authorization = await memory.authorizeResume(
                bufferId,
                {
                    ...binding,
                    explicitlyReviewedRevision:
                        initial.explicitlyReviewedRevision,
                },
                async (candidate) => {
                    if (
                        initial.acceptSelectedFiles === false &&
                        candidate.selectedFiles?.length
                    ) {
                        lastFailure = 'failed';
                        warn(
                            'This form cannot restore the original selected files in its current mode. Open the matching attachment-capable form to recover this browser copy; its text and files have been retained.',
                            'failed',
                        );
                        return { status: 'failed' };
                    }
                    if (
                        initial.acceptedFields &&
                        [
                            candidate.snapshot,
                            candidate.pendingSave?.snapshot,
                            candidate.pendingTask && {
                                fields: candidate.pendingTask.fields,
                            },
                            candidate.pendingApproval && {
                                fields: candidate.pendingApproval.fields,
                            },
                        ].some(
                            (snapshot) =>
                                snapshot &&
                                Object.keys(snapshot.fields).some(
                                    (field) =>
                                        !initial.acceptedFields!.includes(
                                            field,
                                        ),
                                ),
                        )
                    ) {
                        lastFailure = 'failed';
                        warn(
                            'Open the matching ticket editor to recover this browser copy. Its fields have been retained without changing this form.',
                            'failed',
                        );
                        return { status: 'failed' };
                    }
                    try {
                        const response = await axios.post(
                            candidate.kind === 'memory'
                                ? candidate.context.purpose === 'task_work'
                                    ? `/it/tickets/${candidate.context.ticketId}/task-candidates/validate`
                                    : candidate.context.purpose ===
                                        'approval_work'
                                      ? `/it/tickets/${candidate.context.ticketId}/approval-candidates/validate`
                                      : candidate.context.purpose ===
                                          'merge_work'
                                        ? `/it/tickets/${candidate.context.ticketId}/merge-candidates/validate`
                                        : '/it/drafts/validate-local-candidate'
                                : `/it/drafts/${candidate.draftUuid}/validate-candidate`,
                            candidate.kind === 'memory'
                                ? {
                                      actor_user_id: candidate.actorId,
                                      purpose: candidate.context.purpose,
                                      ...(candidate.context.purpose ===
                                      'task_work'
                                          ? {
                                                operation:
                                                    candidate.context.operation,
                                                task_id:
                                                    candidate.context.taskId,
                                            }
                                          : {}),
                                      ...(candidate.context.purpose ===
                                      'approval_work'
                                          ? {
                                                operation:
                                                    candidate.context.operation,
                                                approval_id:
                                                    candidate.context
                                                        .approvalId,
                                            }
                                          : {}),
                                      ...(candidate.context.ticketId !==
                                      undefined
                                          ? {
                                                ticket_id:
                                                    candidate.context.ticketId,
                                            }
                                          : {
                                                request_uuid:
                                                    candidate.context
                                                        .requestUuid,
                                            }),
                                      memory_uuid: candidate.memoryUuid,
                                      candidate_uuid: candidate.candidateUuid,
                                      ...candidate.snapshot,
                                      bound_scopes: candidate.boundScopes ?? [],
                                      ...(candidate.pendingApproval
                                          ? {
                                                pending_approval: {
                                                    actor_user_id:
                                                        candidate
                                                            .pendingApproval
                                                            .actorId,
                                                    ticket_id:
                                                        candidate
                                                            .pendingApproval
                                                            .ticketId,
                                                    request_uuid:
                                                        candidate
                                                            .pendingApproval
                                                            .requestUuid,
                                                    operation:
                                                        candidate
                                                            .pendingApproval
                                                            .operation,
                                                    approval_id:
                                                        candidate
                                                            .pendingApproval
                                                            .approvalId,
                                                    expected_version:
                                                        candidate
                                                            .pendingApproval
                                                            .expectedVersion,
                                                    fields: candidate
                                                        .pendingApproval.fields,
                                                },
                                            }
                                          : {}),
                                      ...(candidate.pendingTask
                                          ? {
                                                pending_task: {
                                                    actor_user_id:
                                                        candidate.pendingTask
                                                            .actorId,
                                                    ticket_id:
                                                        candidate.pendingTask
                                                            .ticketId,
                                                    request_uuid:
                                                        candidate.pendingTask
                                                            .requestUuid,
                                                    operation:
                                                        candidate.pendingTask
                                                            .operation,
                                                    task_id:
                                                        candidate.pendingTask
                                                            .taskId,
                                                    expected_version:
                                                        candidate.pendingTask
                                                            .expectedVersion,
                                                    fields: candidate
                                                        .pendingTask.fields,
                                                },
                                            }
                                          : {}),
                                  }
                                : {
                                      actor_user_id: candidate.actorId,
                                      expected_revision:
                                          candidate.authorizationRevision,
                                      candidate_uuid: candidate.candidateUuid,
                                      ...candidate.snapshot,
                                      bound_scopes: candidate.boundScopes ?? [],
                                  },
                            {
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                signal: request.signal,
                                timeout: initial.timeoutMs ?? 30000,
                            },
                        );
                        if (!same()) return { status: 'failed' };
                        checkedUuid = candidate.draftUuid ?? null;
                        checkedCandidate = candidate;
                        checkedRevision = candidate.authorizationRevision;
                        return {
                            status: 'response',
                            httpStatus: response.status,
                            body: response.data,
                        };
                    } catch (error) {
                        return { status: same() ? failure(error) : 'failed' };
                    }
                },
            );
            if (!same()) return null;
            if (authorization.status !== 'authorized') {
                if (
                    !lastFailure &&
                    !['denied', 'session_expired', 'conflict'].includes(
                        authorization.status,
                    )
                )
                    warn(
                        'The retained browser draft could not be authorized. Its content has not been restored.',
                        'failed',
                    );
                return null;
            }
            if (initial.acceptsSnapshot) {
                const checked =
                    checkedCandidate as ItDraftMemoryCandidate | null;
                if (
                    checked?.pendingApproval &&
                    initial.acceptsPendingApproval &&
                    !initial.acceptsPendingApproval(checked.pendingApproval)
                ) {
                    warn(
                        'Another approval command is active. Resolve it before resuming this retained original proposal.',
                        'failed',
                    );
                    return null;
                }
                if (
                    !checked ||
                    [checked.snapshot, checked.pendingSave?.snapshot].some(
                        (snapshot) =>
                            snapshot &&
                            !initial.acceptsSnapshot!(
                                structuredClone(snapshot),
                            ),
                    )
                ) {
                    warn(
                        'Open the matching ticket editor to recover this browser copy. Its fields have been retained without changing this form.',
                        'failed',
                    );
                    return null;
                }
            }
            if (authorization.localAuthorization) {
                const checked =
                    checkedCandidate as ItDraftMemoryCandidate | null;
                if (
                    checked?.pendingTask &&
                    initial.acceptsPendingTask &&
                    !initial.acceptsPendingTask(checked.pendingTask)
                ) {
                    warn(
                        'Another task command is active in this editor. The retained original command has not been adopted or discarded. Resolve the active command before resuming this copy.',
                        'failed',
                    );
                    return null;
                }
                const candidate = memory.adopt(authorization.permit, binding);
                if (!candidate || candidate.kind !== 'memory') {
                    warn(
                        'Your browser draft changed during recovery. Review it again before continuing.',
                        'conflict',
                    );
                    return null;
                }
                localContext.current = {
                    scope: originalScope,
                    uuid: candidate.memoryUuid,
                    boundScopes: candidate.boundScopes ?? [],
                };
                if (
                    candidate.pendingComment ||
                    candidate.pendingTask ||
                    candidate.pendingApproval
                ) {
                    // No await between removal and re-retention: the same capacity
                    // has just been released, and no other owner can race this step.
                    const held = memory.retain(candidate);
                    if (held.status !== 'stored')
                        throw new Error(
                            'The command recovery handoff could not be retained.',
                        );
                    commentHandoff.current = {
                        id: held.notice.bufferId,
                        scope: originalScope,
                        requestUuid: (candidate.pendingComment ??
                            candidate.pendingTask ??
                            candidate.pendingApproval)!.requestUuid,
                    };
                }
                publish();
                return {
                    candidate,
                    serverResumed: null,
                    localAuthorization: authorization.localAuthorization,
                };
            }
            if (!checkedUuid || checkedRevision === null) return null;
            const response = await axios.post(
                `/it/drafts/${checkedUuid}/resume`,
                { actor_user_id: binding.actorId },
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: request.signal,
                    timeout: initial.timeoutMs ?? 30000,
                },
            );
            if (!same()) return null;
            const body: unknown = response.data;
            const draft = draftRecord(body)
                ? readDraftMetadata(body.draft, binding.context)
                : null;
            const payload = draftRecord(body)
                ? readDraftPayload(body.payload, binding.context.purpose)
                : null;
            const files =
                draftRecord(body) && Array.isArray(body.attachments)
                    ? body.attachments.map(readDraftAttachment)
                    : null;
            if (
                response.status !== 200 ||
                !draft ||
                !draft.capabilities.read ||
                draft.state !== 'active' ||
                draft.draft_uuid !== checkedUuid ||
                draft.revision !== checkedRevision ||
                !payload ||
                !files ||
                files.length > 5 ||
                files.some((file) => !file) ||
                new Set(files.map((file) => file?.id)).size !== files.length ||
                new Set(files.map((file) => file?.upload_uuid)).size !==
                    files.length
            ) {
                warn(
                    'The saved draft changed or could not be verified during recovery. Your browser copy is retained; review the draft and try again.',
                    'conflict',
                );
                return null;
            }
            const candidate = memory.adopt(authorization.permit, binding);
            if (!candidate) {
                warn(
                    'Your browser draft changed during recovery. Review it again before continuing.',
                    'conflict',
                );
                return null;
            }
            publish();
            return {
                candidate,
                serverResumed: {
                    draft,
                    payload,
                    attachments: files as ItDraftAttachment[],
                },
            };
        } catch (error) {
            if (same()) failure(error);
            return null;
        } finally {
            if (epoch.current === token) {
                controller.current = null;
                if (mounted.current)
                    setState((value) => ({ ...value, busy: false }));
            }
        }
    };
    const binding = { actorId: options.actorId!, context: options.context };
    const visible =
        state.scope === scope
            ? state
            : { warning: null, busy: false, failure: null };
    const suppressCurrentSnapshot = () => {
        const current = latest.current;
        explicitlyCleared.current = {
            scope: draftScopeKey(current.actorId, current.context),
            snapshotKey: current.workingSnapshot
                ? draftSnapshotKey(current.workingSnapshot)
                : null,
            files: [...(current.workingFiles ?? [])],
        };
        localContext.current = null;
    };
    return {
        notices:
            options.enabled &&
            options.actorId === currentActor &&
            validDraftContext(options.actorId, options.context)
                ? memory
                      .discover(options.actorId!, options.context)
                      .filter((notice) => !owners.has(notice.bufferId))
                : [],
        ...visible,
        cancel: () => {
            if (!controller.current) return;
            abortRequest();
            if (mounted.current)
                setState({
                    scope: scopeRef.current,
                    warning:
                        'Recovery wait stopped. Your browser copy remains available for a new attempt.',
                    failure: null,
                    busy: false,
                });
        },
        resume,
        ensureLatestRetained(): ItDraftRetentionResult {
            const retained = retainLatest();
            if (retained.status === 'blocked') warn(retained.message);
            return retained;
        },
        discardLocal(bufferId: string) {
            if (owners.has(bufferId)) return false;
            const removed = memory.discardBuffer(bufferId, binding);
            if (removed) publish();
            return removed;
        },
        purgeGeneration(uuid: string) {
            memory.purgeGeneration(binding.actorId, binding.context, uuid);
            publish();
        },
        acknowledgeConsumed(
            candidate: Pick<
                ItPersistedDraftMemoryCandidate,
                'draftUuid' | 'revision' | 'snapshot'
            >,
        ) {
            memory.acknowledgeConsumed({ ...binding, ...candidate });
            publish();
        },
        clearOwnedWork() {
            if (commentHandoff.current?.scope === scope) {
                memory.discardBuffer(commentHandoff.current.id, binding);
                commentHandoff.current = null;
                publish();
            }
            suppressCurrentSnapshot();
            release(true);
        },
        clearCurrentScope() {
            commentHandoff.current = null;
            suppressCurrentSnapshot();
            memory.purgeContext(binding.actorId, binding.context);
            release(false);
            publish();
        },
    };
}

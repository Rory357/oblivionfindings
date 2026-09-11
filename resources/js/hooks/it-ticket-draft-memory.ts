import {
    approvalFieldNames,
    freezeItApprovalIntent,
    type ItApprovalIntent,
} from './it-ticket-approval-contract';
import {
    freezeItCommentIntent,
    type ItCommentIntent,
} from './it-ticket-comment-contract';
import {
    IT_DRAFT_UUID,
    draftContextKey,
    draftRecord,
    draftScopeKey,
    draftSnapshotKey,
    readDraftMetadata,
    readDraftPayload,
    validDraftContext,
    type ItDraftContext,
    type ItDraftFields,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import {
    freezeItWorkTaskIntent,
    taskFieldNames,
    type ItWorkTaskIntent,
} from './it-work-task-command';

export type ItDraftBoundScope = Pick<
    ItDraftFields,
    | 'site_id'
    | 'is_organisation_wide'
    | 'assigned_to_user_id'
    | 'owner_user_id'
    | 'requester_user_id'
    | 'asset_id'
    | 'device_id'
    | 'it_service_id'
    | 'provisioning_request_id'
    | 'queue_id'
    | 'watchers'
    | 'team_id'
    | 'dependency_ids'
    | 'ordered_ids'
    | 'primary_approver_user_id'
    | 'cover_approver_user_id'
    | 'target_ticket_id'
> & { approval_ids?: number[] };
interface CandidateContent {
    actorId: number;
    context: ItDraftContext;
    snapshot: ItDraftSnapshot;
    /** The original save can differ from newer text typed while its request ran. */
    pendingSave?: { snapshot: ItDraftSnapshot; revision: number };
    /** Preserve the original File object and upload identity; never fabricate a saved file. */
    pendingUpload?: { file: File; uploadUuid: string; revision: number };
    /** Immutable canonical reply intent; retained locally even if its saved draft was consumed. */
    pendingComment?: Readonly<ItCommentIntent>;
    /** Exact task command retained in the same bounded, authorized RAM inventory. */
    pendingTask?: Readonly<ItWorkTaskIntent>;
    pendingApproval?: Readonly<ItApprovalIntent>;
    outcomeUnknown: boolean;
    boundScopes?: ItDraftBoundScope[];
}
export type ItPersistedDraftMemoryCandidate = CandidateContent & {
    kind?: 'persisted';
    draftUuid: string;
    memoryUuid?: never;
    revision: number;
    selectedFiles?: never;
};
export type ItLocalDraftMemoryCandidate = CandidateContent & {
    kind: 'memory';
    memoryUuid: string;
    draftUuid?: never;
    revision: 0;
    selectedFiles?: File[];
};
export type ItDraftMemoryCandidate =
    | ItPersistedDraftMemoryCandidate
    | ItLocalDraftMemoryCandidate;
export interface ItDraftMemoryNotice {
    bufferId: string;
    kind: 'memory' | 'persisted';
    draftUuid: string | null;
    memoryUuid: string | null;
    revision: number;
    hasPendingUpload: boolean;
    hasPendingSave: boolean;
    hasPendingComment: boolean;
    hasPendingTask?: boolean;
    hasPendingApproval?: boolean;
    selectedFileCount: number;
    outcomeUnknown: boolean;
}
interface Entry {
    candidate: ItDraftMemoryCandidate;
    sequence: number;
    bytes: number;
}
interface Grant {
    bufferId: string;
    sequence: number;
    actorId: number;
    scope: string;
}
export type ItDraftMemoryAuthorizationResponse =
    | { status: 'response'; httpStatus: number; body: unknown }
    | { status: 'denied' | 'session_expired' | 'conflict' | 'failed' };
export type ItDraftMemoryAuthorization =
    | {
          status: 'authorized';
          permit: string;
          localAuthorization?: ItLocalDraftAuthorization;
      }
    | {
          status:
              | 'unavailable'
              | 'changed'
              | 'denied'
              | 'session_expired'
              | 'conflict'
              | 'failed';
      };
export interface ItLocalDraftAuthorization {
    kind: 'memory';
    memory_uuid: string;
    candidate_uuid: string;
    actor_user_id: number;
    purpose: ItDraftContext['purpose'];
    context_key: string;
    base_ticket_version: number | null;
    current_ticket_version: number | null;
    authorized: true;
    capabilities: { submit: boolean };
    blocker: { code: string; message: string } | null;
}

const clone = (candidate: ItDraftMemoryCandidate): ItDraftMemoryCandidate =>
    ({
        ...candidate,
        context: { ...candidate.context },
        snapshot: structuredClone(candidate.snapshot),
        ...(candidate.boundScopes
            ? { boundScopes: structuredClone(candidate.boundScopes) }
            : {}),
        ...(candidate.kind === 'memory' && candidate.selectedFiles
            ? { selectedFiles: [...candidate.selectedFiles] }
            : {}),
        ...(candidate.pendingSave
            ? { pendingSave: structuredClone(candidate.pendingSave) }
            : {}),
        ...(candidate.pendingUpload
            ? { pendingUpload: { ...candidate.pendingUpload } }
            : {}),
        ...(candidate.pendingComment
            ? {
                  pendingComment: freezeItCommentIntent(
                      candidate.pendingComment,
                  ),
              }
            : {}),
        ...(candidate.pendingTask
            ? { pendingTask: freezeItWorkTaskIntent(candidate.pendingTask)! }
            : {}),
        ...(candidate.pendingApproval
            ? {
                  pendingApproval: freezeItApprovalIntent(
                      candidate.pendingApproval,
                      candidate.pendingApproval.fields,
                  )!,
              }
            : {}),
    }) as ItDraftMemoryCandidate;
const natural = (value: number) => Number.isSafeInteger(value) && value >= 0;
const generation = (candidate: ItDraftMemoryCandidate) =>
    candidate.kind === 'memory' ? candidate.memoryUuid : candidate.draftUuid;
const boundKeys = new Set([
    'target_ticket_id',
    'site_id',
    'is_organisation_wide',
    'assigned_to_user_id',
    'owner_user_id',
    'requester_user_id',
    'asset_id',
    'device_id',
    'it_service_id',
    'provisioning_request_id',
    'queue_id',
    'watchers',
    'team_id',
    'dependency_ids',
    'ordered_ids',
    'approval_ids',
    'primary_approver_user_id',
    'cover_approver_user_id',
]);
export function validItDraftBoundScopes(
    scopes: unknown,
): scopes is ItDraftBoundScope[] {
    return (
        Array.isArray(scopes) &&
        scopes.length <= 100 &&
        scopes.every(
            (scope) =>
                draftRecord(scope) &&
                Object.entries(scope).every(
                    ([key, value]) =>
                        boundKeys.has(key) &&
                        (key === 'is_organisation_wide'
                            ? typeof value === 'boolean'
                            : [
                                    'watchers',
                                    'dependency_ids',
                                    'ordered_ids',
                                    'approval_ids',
                                ].includes(key)
                              ? value === null ||
                                (Array.isArray(value) &&
                                    value.length <=
                                        (key === 'watchers' ? 100 : 1000) &&
                                    new Set(value).size === value.length &&
                                    value.every(
                                        (id) =>
                                            Number.isSafeInteger(id) && id > 0,
                                    ))
                              : value === null ||
                                (Number.isSafeInteger(value) &&
                                    (value as number) > 0)),
                ),
        )
    );
}
export function itDraftBoundScope(
    snapshot: ItDraftSnapshot,
): ItDraftBoundScope {
    const scope = Object.fromEntries(
        Object.entries(snapshot.fields).filter(
            ([key, value]) =>
                boundKeys.has(key) &&
                value !== null &&
                value !== undefined &&
                value !== '' &&
                (!Array.isArray(value) || value.length > 0),
        ),
    ) as ItDraftBoundScope;
    if (
        Number.isSafeInteger(snapshot.fields.approval_id) &&
        Number(snapshot.fields.approval_id) > 0
    )
        scope.approval_ids = [snapshot.fields.approval_id!];
    return scope;
}
export const newItDraftMemoryUuid = () => uuid();
const uuid = () => {
    if (typeof globalThis.crypto?.randomUUID === 'function')
        return crypto.randomUUID();
    // The same secure fallback used by the canonical IT command client.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/**
 * A document-memory extension of the canonical draft client. The caller chooses
 * reviewed resource bounds and owns lifecycle integration. No browser storage,
 * history, network, timers, automatic save, or automatic disclosure occurs here.
 */
export function createItDraftMemoryStore(limits: {
    maxEntries: number;
    maxBytes: number;
}) {
    if (
        !Number.isSafeInteger(limits.maxEntries) ||
        limits.maxEntries < 1 ||
        !Number.isSafeInteger(limits.maxBytes) ||
        limits.maxBytes < 1
    )
        throw new Error('Draft memory requires explicit positive bounds.');
    const entries = new Map<string, Entry>();
    const grants = new Map<string, Grant>();
    let sequence = 0;
    const remove = (bufferId: string) => {
        entries.delete(bufferId);
        for (const [permit, grant] of grants)
            if (grant.bufferId === bufferId) grants.delete(permit);
    };
    const notice = (bufferId: string, entry: Entry): ItDraftMemoryNotice => ({
        bufferId,
        kind: entry.candidate.kind ?? 'persisted',
        draftUuid: entry.candidate.draftUuid ?? null,
        memoryUuid: entry.candidate.memoryUuid ?? null,
        revision: entry.candidate.revision,
        hasPendingUpload: !!entry.candidate.pendingUpload,
        hasPendingSave: !!entry.candidate.pendingSave,
        hasPendingComment: !!entry.candidate.pendingComment,
        hasPendingTask: !!entry.candidate.pendingTask,
        ...(entry.candidate.pendingApproval
            ? { hasPendingApproval: true }
            : {}),
        selectedFileCount: entry.candidate.selectedFiles?.length ?? 0,
        outcomeUnknown: entry.candidate.outcomeUnknown,
    });
    const sameScope = (
        candidate: ItDraftMemoryCandidate,
        actorId: number,
        context: ItDraftContext,
    ) =>
        candidate.actorId === actorId &&
        draftScopeKey(candidate.actorId, candidate.context) ===
            draftScopeKey(actorId, context);

    return {
        /** Atomic handoff of this mounted form after its canonical draft is initialized. */
        initializeOwnedDraft(
            bufferId: string,
            candidate: ItPersistedDraftMemoryCandidate,
        ) {
            const previous = entries.get(bufferId);
            if (
                !previous ||
                previous.candidate.kind !== 'memory' ||
                previous.candidate.outcomeUnknown ||
                !sameScope(
                    previous.candidate,
                    candidate.actorId,
                    candidate.context,
                ) ||
                previous.candidate.selectedFiles?.length
            )
                return { status: 'scope_changed' as const };
            // Synchronous private replacement: no observer can read a missing entry,
            // and a rejected candidate restores both the original data and permits.
            entries.delete(bufferId);
            const result = this.retain(candidate);
            if (result.status !== 'stored') entries.set(bufferId, previous);
            else
                for (const [permit, grant] of grants)
                    if (grant.bufferId === bufferId) grants.delete(permit);
            return result;
        },
        retain(
            candidate: ItDraftMemoryCandidate,
            bufferId?: string,
        ):
            | { status: 'stored'; notice: ItDraftMemoryNotice }
            | { status: 'invalid' | 'capacity' | 'scope_changed' | 'frozen' } {
            const payload = readDraftPayload(
                candidate.snapshot,
                candidate.context.purpose,
            );
            const pendingPayload = candidate.pendingSave
                ? readDraftPayload(
                      candidate.pendingSave.snapshot,
                      candidate.context.purpose,
                  )
                : null;
            let pendingComment: Readonly<ItCommentIntent> | undefined;
            const pendingTask = candidate.pendingTask
                ? freezeItWorkTaskIntent(candidate.pendingTask)
                : undefined;
            const pendingApproval = candidate.pendingApproval
                ? freezeItApprovalIntent(
                      candidate.pendingApproval,
                      candidate.pendingApproval.fields,
                  )
                : undefined;
            try {
                if (candidate.pendingComment)
                    pendingComment = freezeItCommentIntent(
                        candidate.pendingComment,
                    );
            } catch {
                return { status: 'invalid' };
            }
            const candidateContext = candidate.context;
            if (
                !validDraftContext(candidate.actorId, candidate.context) ||
                !IT_DRAFT_UUID.test(generation(candidate)) ||
                !natural(candidate.revision) ||
                (candidate.pendingTask && !pendingTask) ||
                (candidate.pendingApproval && !pendingApproval) ||
                (candidateContext.purpose === 'merge_work' &&
                    (candidate.kind !== 'memory' ||
                        !!candidate.pendingSave ||
                        !!candidate.pendingUpload ||
                        !!candidate.pendingComment ||
                        !!candidate.pendingTask ||
                        !!candidate.pendingApproval ||
                        !!candidate.selectedFiles?.length)) ||
                (candidateContext.purpose === 'approval_work' &&
                    (candidate.kind !== 'memory' ||
                        !!candidate.pendingSave ||
                        !!candidate.pendingUpload ||
                        !!candidate.pendingComment ||
                        !!candidate.pendingTask ||
                        !!candidate.selectedFiles?.length ||
                        !payload ||
                        Object.keys(payload.fields).some(
                            (field) =>
                                !approvalFieldNames[
                                    candidateContext.operation
                                ].includes(field),
                        ))) ||
                (pendingApproval &&
                    (candidate.context.purpose !== 'approval_work' ||
                        !candidate.outcomeUnknown ||
                        pendingApproval.actorId !== candidate.actorId ||
                        pendingApproval.ticketId !==
                            candidate.context.ticketId ||
                        pendingApproval.operation !==
                            candidate.context.operation ||
                        pendingApproval.approvalId !==
                            candidate.context.approvalId ||
                        pendingApproval.expectedVersion !==
                            candidate.snapshot.base_ticket_version)) ||
                (candidateContext.purpose === 'task_work' &&
                    (candidate.kind !== 'memory' ||
                        !!candidate.pendingSave ||
                        !!candidate.pendingUpload ||
                        !!candidate.pendingComment ||
                        !!candidate.selectedFiles?.length ||
                        !payload ||
                        Object.keys(payload.fields).some(
                            (field) =>
                                !taskFieldNames[
                                    candidateContext.operation
                                ].includes(field),
                        ))) ||
                (pendingTask &&
                    (candidate.context.purpose !== 'task_work' ||
                        !candidate.outcomeUnknown ||
                        pendingTask.actorId !== candidate.actorId ||
                        pendingTask.ticketId !== candidate.context.ticketId ||
                        pendingTask.operation !== candidate.context.operation ||
                        pendingTask.taskId !== candidate.context.taskId ||
                        pendingTask.expectedVersion !==
                            candidate.snapshot.base_ticket_version)) ||
                (pendingComment &&
                    (candidate.kind !== 'memory' ||
                        !!candidate.pendingSave ||
                        !!candidate.pendingUpload ||
                        !candidate.outcomeUnknown ||
                        pendingComment.actorId !== candidate.actorId ||
                        pendingComment.ticketId !==
                            candidate.context.ticketId ||
                        candidate.context.purpose !==
                            (pendingComment.isInternal
                                ? 'internal_note'
                                : 'public_reply'))) ||
                (candidate.kind === 'memory' &&
                    (candidate.revision !== 0 ||
                        !!candidate.pendingSave ||
                        !!candidate.pendingUpload)) ||
                (candidate.boundScopes !== undefined &&
                    !validItDraftBoundScopes(candidate.boundScopes)) ||
                (candidate.selectedFiles &&
                    (candidate.kind !== 'memory' ||
                        [
                            'public_resolution',
                            'ticket_edit',
                            'task_work',
                            'approval_work',
                            'merge_work',
                        ].includes(candidate.context.purpose) ||
                        candidate.selectedFiles.length > 5 ||
                        candidate.selectedFiles.some(
                            (file) =>
                                !(file instanceof File) ||
                                file.size > 10 * 1024 * 1024,
                        ))) ||
                typeof candidate.outcomeUnknown !== 'boolean' ||
                !payload ||
                (candidate.pendingSave &&
                    (!!candidate.pendingUpload ||
                        !pendingPayload ||
                        !natural(candidate.pendingSave.revision) ||
                        (candidate.pendingSave.snapshot.base_ticket_version !=
                            null &&
                            (!Number.isSafeInteger(
                                candidate.pendingSave.snapshot
                                    .base_ticket_version,
                            ) ||
                                candidate.pendingSave.snapshot
                                    .base_ticket_version < 1)))) ||
                (candidate.snapshot.base_ticket_version != null &&
                    (!Number.isSafeInteger(
                        candidate.snapshot.base_ticket_version,
                    ) ||
                        candidate.snapshot.base_ticket_version < 1)) ||
                (candidate.pendingUpload &&
                    ([
                        'public_resolution',
                        'ticket_edit',
                        'task_work',
                        'approval_work',
                        'merge_work',
                    ].includes(candidate.context.purpose) ||
                        !(candidate.pendingUpload.file instanceof File) ||
                        !IT_DRAFT_UUID.test(
                            candidate.pendingUpload.uploadUuid,
                        ) ||
                        !natural(candidate.pendingUpload.revision)))
            )
                return { status: 'invalid' };
            const previous = bufferId ? entries.get(bufferId) : undefined;
            if (
                bufferId &&
                (!previous ||
                    !sameScope(
                        previous.candidate,
                        candidate.actorId,
                        candidate.context,
                    ) ||
                    generation(previous.candidate) !== generation(candidate) ||
                    (previous.candidate.kind ?? 'persisted') !==
                        (candidate.kind ?? 'persisted'))
            )
                return { status: 'scope_changed' };
            if (
                previous?.candidate.outcomeUnknown &&
                (!candidate.outcomeUnknown ||
                    previous.candidate.revision !== candidate.revision ||
                    previous.candidate.pendingUpload?.file !==
                        candidate.pendingUpload?.file ||
                    previous.candidate.pendingUpload?.uploadUuid !==
                        candidate.pendingUpload?.uploadUuid ||
                    previous.candidate.pendingUpload?.revision !==
                        candidate.pendingUpload?.revision ||
                    previous.candidate.pendingSave?.revision !==
                        candidate.pendingSave?.revision ||
                    JSON.stringify(
                        previous.candidate.pendingComment ?? null,
                    ) !== JSON.stringify(pendingComment ?? null) ||
                    JSON.stringify(previous.candidate.pendingTask ?? null) !==
                        JSON.stringify(pendingTask ?? null) ||
                    JSON.stringify(
                        previous.candidate.pendingApproval ?? null,
                    ) !== JSON.stringify(pendingApproval ?? null) ||
                    previous.candidate.pendingComment?.files.some(
                        (file, index) => file !== pendingComment?.files[index],
                    ) ||
                    (previous.candidate.pendingSave
                        ? draftSnapshotKey(
                              previous.candidate.pendingSave.snapshot,
                          )
                        : null) !==
                        (candidate.pendingSave
                            ? draftSnapshotKey(candidate.pendingSave.snapshot)
                            : null) ||
                    previous.candidate.selectedFiles?.length !==
                        candidate.selectedFiles?.length ||
                    previous.candidate.selectedFiles?.some(
                        (file, index) =>
                            file !== candidate.selectedFiles?.[index],
                    ))
            )
                return { status: 'frozen' };
            const safe = {
                ...candidate,
                ...(pendingComment ? { pendingComment } : {}),
                ...(pendingTask ? { pendingTask } : {}),
                ...(pendingApproval ? { pendingApproval } : {}),
                snapshot: {
                    ...payload!,
                    ...(candidate.context.ticketId !== undefined
                        ? {
                              base_ticket_version:
                                  candidate.snapshot.base_ticket_version ??
                                  null,
                          }
                        : {}),
                },
                ...(candidate.pendingSave
                    ? {
                          pendingSave: {
                              revision: candidate.pendingSave.revision,
                              snapshot: {
                                  ...pendingPayload!,
                                  ...(candidate.context.ticketId !== undefined
                                      ? {
                                            base_ticket_version:
                                                candidate.pendingSave.snapshot
                                                    .base_ticket_version ??
                                                null,
                                        }
                                      : {}),
                              },
                          },
                      }
                    : {}),
            };
            const bytes =
                new TextEncoder().encode(JSON.stringify(safe.snapshot)).length +
                (safe.pendingSave
                    ? new TextEncoder().encode(
                          JSON.stringify(safe.pendingSave.snapshot),
                      ).length
                    : 0) +
                [
                    ...new Set([
                        ...(candidate.pendingUpload
                            ? [candidate.pendingUpload.file]
                            : []),
                        ...(candidate.selectedFiles ?? []),
                        ...(pendingComment?.files ?? []),
                    ]),
                ].reduce((sum, file) => sum + file.size, 0) +
                (pendingComment
                    ? new TextEncoder().encode(JSON.stringify(pendingComment))
                          .length
                    : 0) +
                (pendingTask
                    ? new TextEncoder().encode(JSON.stringify(pendingTask))
                          .length
                    : 0) +
                (pendingApproval
                    ? new TextEncoder().encode(JSON.stringify(pendingApproval))
                          .length
                    : 0) +
                new TextEncoder().encode(
                    JSON.stringify(candidate.boundScopes ?? []),
                ).length;
            const total = Array.from(entries.values()).reduce(
                (sum, entry) => sum + entry.bytes,
                0,
            );
            if (
                (!previous && entries.size >= limits.maxEntries) ||
                total - (previous?.bytes ?? 0) + bytes > limits.maxBytes
            )
                return { status: 'capacity' };
            const id = bufferId ?? uuid();
            if (previous) remove(id);
            const entry = {
                candidate: clone(safe),
                sequence: ++sequence,
                bytes,
            };
            entries.set(id, entry);
            return { status: 'stored', notice: notice(id, entry) };
        },

        discover(
            actorId: number,
            context: ItDraftContext,
        ): ItDraftMemoryNotice[] {
            if (!validDraftContext(actorId, context)) return [];
            return Array.from(entries.entries()).flatMap(([id, entry]) =>
                sameScope(entry.candidate, actorId, context)
                    ? [notice(id, entry)]
                    : [],
            );
        },

        discoverRequests(
            actorId: number,
            purpose: 'requester_intake' | 'technician_intake',
        ) {
            return Array.from(entries.entries()).flatMap(([id, entry]) =>
                entry.candidate.actorId === actorId &&
                entry.candidate.context.purpose === purpose &&
                entry.candidate.context.requestUuid
                    ? [
                          {
                              ...notice(id, entry),
                              requestUuid: entry.candidate.context.requestUuid,
                          },
                      ]
                    : [],
            );
        },

        discoverApprovalWork(actorId: number, ticketId: number) {
            return Array.from(entries.entries()).flatMap(([id, entry]) =>
                entry.candidate.actorId === actorId &&
                entry.candidate.context.purpose === 'approval_work' &&
                entry.candidate.context.ticketId === ticketId
                    ? [
                          {
                              ...notice(id, entry),
                              context: { ...entry.candidate.context },
                          },
                      ]
                    : [],
            );
        },

        /** Opaque task contexts only; current authorization is still required to resume. */
        discoverTaskWork(actorId: number, ticketId: number) {
            return Array.from(entries.entries()).flatMap(([id, entry]) =>
                entry.candidate.actorId === actorId &&
                entry.candidate.context.purpose === 'task_work' &&
                entry.candidate.context.ticketId === ticketId
                    ? [
                          {
                              ...notice(id, entry),
                              context: { ...entry.candidate.context },
                          },
                      ]
                    : [],
            );
        },

        /**
         * Call only from explicit Resume. The callback must validate the fresh
         * canonical response, including this nonce and the candidate's bindings.
         * It receives a private copy for that check, never for rendering.
         */
        async authorizeResume(
            bufferId: string,
            binding: {
                actorId: number;
                context: ItDraftContext;
                explicitlyReviewedRevision?: number;
            },
            authorizeCandidate: (
                candidate: ItDraftMemoryCandidate & {
                    candidateUuid: string;
                    authorizationRevision: number;
                },
            ) => Promise<ItDraftMemoryAuthorizationResponse>,
        ): Promise<ItDraftMemoryAuthorization> {
            const entry = entries.get(bufferId);
            if (
                !entry ||
                !sameScope(entry.candidate, binding.actorId, binding.context)
            )
                return { status: 'unavailable' };
            const authorizationRevision =
                binding.explicitlyReviewedRevision ?? entry.candidate.revision;
            if (
                !natural(authorizationRevision) ||
                authorizationRevision < entry.candidate.revision
            )
                return { status: 'unavailable' };
            const attempt = ++sequence;
            entry.sequence = attempt;
            for (const [permit, grant] of grants)
                if (grant.bufferId === bufferId) grants.delete(permit);
            let decision: ItDraftMemoryAuthorizationResponse;
            const candidateUuid = uuid();
            try {
                decision = await authorizeCandidate({
                    ...clone(entry.candidate),
                    candidateUuid,
                    authorizationRevision,
                });
            } catch {
                return entries.get(bufferId)?.sequence === attempt
                    ? { status: 'failed' }
                    : { status: 'changed' };
            }
            if (entries.get(bufferId)?.sequence !== attempt)
                return { status: 'changed' };
            if (decision.status === 'denied') {
                remove(bufferId);
                return { status: 'denied' };
            }
            if (decision.status === 'session_expired')
                return { status: 'session_expired' };
            if (decision.status === 'conflict') return { status: 'conflict' };
            if (
                decision.status !== 'response' ||
                decision.httpStatus !== 200 ||
                !draftRecord(decision.body)
            )
                return { status: 'failed' };
            const proof = decision.body.candidate;
            if (
                !draftRecord(proof) ||
                proof.authorized !== true ||
                proof.candidate_uuid !== candidateUuid ||
                proof.actor_user_id !== entry.candidate.actorId ||
                proof.purpose !== entry.candidate.context.purpose ||
                proof.context_key !==
                    draftContextKey(entry.candidate.context) ||
                proof.base_ticket_version !==
                    (entry.candidate.snapshot.base_ticket_version ?? null)
            )
                return { status: 'failed' };
            let localAuthorization: ItLocalDraftAuthorization | undefined;
            if (entry.candidate.kind === 'memory') {
                if (
                    proof.kind !== 'memory' ||
                    proof.memory_uuid !== entry.candidate.memoryUuid ||
                    (proof.current_ticket_version !== null &&
                        (!Number.isSafeInteger(proof.current_ticket_version) ||
                            (proof.current_ticket_version as number) < 1)) ||
                    !draftRecord(proof.capabilities) ||
                    typeof proof.capabilities.submit !== 'boolean' ||
                    (proof.blocker !== null &&
                        (!draftRecord(proof.blocker) ||
                            typeof proof.blocker.code !== 'string' ||
                            typeof proof.blocker.message !== 'string'))
                )
                    return { status: 'failed' };
                if (proof.blocker !== null && proof.capabilities.submit)
                    return { status: 'failed' };
                localAuthorization = {
                    kind: 'memory',
                    memory_uuid: entry.candidate.memoryUuid,
                    candidate_uuid: candidateUuid,
                    actor_user_id: entry.candidate.actorId,
                    purpose: entry.candidate.context.purpose,
                    context_key: draftContextKey(entry.candidate.context),
                    base_ticket_version:
                        entry.candidate.snapshot.base_ticket_version ?? null,
                    current_ticket_version: proof.current_ticket_version as
                        | number
                        | null,
                    authorized: true,
                    capabilities: { submit: proof.capabilities.submit },
                    blocker:
                        proof.blocker === null
                            ? null
                            : {
                                  code: (
                                      proof.blocker as Record<string, string>
                                  ).code,
                                  message: (
                                      proof.blocker as Record<string, string>
                                  ).message,
                              },
                };
            } else {
                const metadata = readDraftMetadata(
                    decision.body.draft,
                    entry.candidate.context,
                );
                if (
                    proof.draft_uuid !== entry.candidate.draftUuid ||
                    proof.revision !== authorizationRevision ||
                    !metadata ||
                    !metadata.capabilities.read ||
                    metadata.state !== 'active' ||
                    metadata.draft_uuid !== entry.candidate.draftUuid ||
                    metadata.revision !== authorizationRevision
                )
                    return { status: 'failed' };
            }
            const permit = uuid();
            grants.set(permit, {
                bufferId,
                sequence: attempt,
                actorId: binding.actorId,
                scope: draftScopeKey(binding.actorId, binding.context),
            });
            return {
                status: 'authorized',
                permit,
                ...(localAuthorization ? { localAuthorization } : {}),
            };
        },

        /** Atomically claim once, immediately before the authorized host adopts it. */
        adopt(
            permit: string,
            binding: { actorId: number; context: ItDraftContext },
        ): ItDraftMemoryCandidate | null {
            const grant = grants.get(permit);
            if (!grant) return null;
            grants.delete(permit);
            const entry = entries.get(grant.bufferId);
            if (
                !entry ||
                entry.sequence !== grant.sequence ||
                grant.actorId !== binding.actorId ||
                grant.scope !== draftScopeKey(binding.actorId, binding.context)
            )
                return null;
            const candidate = clone(entry.candidate);
            remove(grant.bufferId);
            return candidate;
        },

        /** Explicit local discard only; does not claim to discard a server draft. */
        discardBuffer(
            bufferId: string,
            binding: { actorId: number; context: ItDraftContext },
        ) {
            const entry = entries.get(bufferId);
            if (
                !entry ||
                !sameScope(entry.candidate, binding.actorId, binding.context)
            )
                return false;
            remove(bufferId);
            return true;
        },

        purgeActor(actorId: number) {
            for (const [id, entry] of entries)
                if (entry.candidate.actorId === actorId) remove(id);
        },
        purgeContext(actorId: number, context: ItDraftContext) {
            for (const [id, entry] of entries)
                if (sameScope(entry.candidate, actorId, context)) remove(id);
        },
        purgeGeneration(
            actorId: number,
            context: ItDraftContext,
            draftUuid: string,
        ) {
            for (const [id, entry] of entries)
                if (
                    sameScope(entry.candidate, actorId, context) &&
                    generation(entry.candidate) === draftUuid
                )
                    remove(id);
        },
        acknowledgeConsumed(
            candidate: Pick<
                ItPersistedDraftMemoryCandidate,
                'actorId' | 'context' | 'draftUuid' | 'revision' | 'snapshot'
            >,
        ) {
            for (const [id, entry] of entries)
                if (
                    sameScope(
                        entry.candidate,
                        candidate.actorId,
                        candidate.context,
                    ) &&
                    entry.candidate.draftUuid === candidate.draftUuid &&
                    entry.candidate.revision === candidate.revision &&
                    draftSnapshotKey(entry.candidate.snapshot) ===
                        draftSnapshotKey(candidate.snapshot)
                )
                    remove(id);
        },
        clear() {
            entries.clear();
            grants.clear();
            ++sequence;
        },
        /** Safe aggregate only; no private text, filenames, paths or identities. */
        usage: () => ({
            entries: entries.size,
            bytes: Array.from(entries.values()).reduce(
                (sum, entry) => sum + entry.bytes,
                0,
            ),
        }),
    };
}

import { isAllowedItAttachmentName } from '@/lib/it-attachments';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { type ItCommentIntent } from './it-ticket-comment-contract';
import {
    IT_DRAFT_UUID,
    draftRecord,
    draftScopeKey,
    draftSnapshotKey,
    readDraftAttachment,
    readDraftMetadata,
    readDraftPayload,
    validDraftContext,
    type ItDraftAttachment,
    type ItDraftCommitReference,
    type ItDraftContext,
    type ItDraftMetadata,
    type ItDraftResumed,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import { useItTicketDraftMemory } from './use-it-ticket-draft-memory';

export interface ItDraftBrowserRestored {
    snapshot: ItDraftSnapshot;
    files: File[];
    pendingComment?: Readonly<ItCommentIntent>;
    outcomeUnknown: boolean;
    canonicalOutcomeUnknown: boolean;
    current_ticket_version: number | null;
    blocker: ItDraftMetadata['blocker'];
}

export type ItDraftClientState =
    | 'disabled'
    | 'idle'
    | 'checking'
    | 'available'
    | 'ready'
    | 'saving'
    | 'reviewing'
    | 'conflict'
    | 'session_expired'
    | 'access_denied'
    | 'outcome_unknown'
    | 'terminal';
type ReadOperation = 'check' | 'resume' | 'review';
type Mutation = 'save' | 'discard' | 'start_new' | 'upload' | 'remove';
type Operation = ReadOperation | Mutation;
interface FrozenOperation {
    kind: Mutation;
    uuid: string;
    revision: number;
    snapshot?: ItDraftSnapshot;
    file?: File;
    uploadUuid?: string;
    attachmentId?: number;
}
interface ClientSnapshot {
    state: ItDraftClientState;
    draft: ItDraftMetadata | null;
    current: ItDraftMetadata | null;
    reviewed: ItDraftResumed | null;
    attachments: ItDraftAttachment[];
    message: string | null;
    errors: Record<string, string>;
    pending: Operation | null;
    retryable: boolean;
    lastSavedKey: string | null;
    browserOutcomeUnknown: boolean;
    browserBlocker: ItDraftMetadata['blocker'];
}
const empty = (enabled: boolean): ClientSnapshot => ({
    state: enabled ? 'idle' : 'disabled',
    draft: null,
    current: null,
    reviewed: null,
    attachments: [],
    message: null,
    errors: {},
    pending: null,
    retryable: false,
    lastSavedKey: null,
    browserOutcomeUnknown: false,
    browserBlocker: null,
});
const messageFor = (code: string) =>
    ({
        session_expired:
            'Sign in again, then check this draft before continuing.',
        access_unavailable:
            'Your access changed. This draft is no longer available here.',
        draft_unavailable: 'This draft is no longer available here.',
        drafts_disabled:
            'Saved draft recovery is not enabled. Keep this form open to retain your work.',
        draft_conflict:
            'This draft changed elsewhere. Review the saved draft before reapplying your changes.',
        draft_terminal:
            'This draft has ended. Check its current state before starting another.',
        draft_submission_exists:
            'This request is already saved. Check its saved result before continuing.',
        draft_upload_conflict:
            'This upload identity belongs to different file content. Review the saved draft, then select the file again deliberately.',
        draft_attachment_removed:
            'This file was removed. Review the saved draft, then select it again to start a new upload.',
        draft_upload_unconfirmed:
            'The upload result is unconfirmed. Keep the selected file and retry that upload.',
        draft_validation: 'Review the highlighted draft details.',
    })[code] ??
    'The draft result could not be confirmed. Keep your work here and check the saved draft before retrying.';
const errorFields = (body: Record<string, unknown>) =>
    draftRecord(body.errors)
        ? Object.fromEntries(
              Object.entries(body.errors).flatMap(([key, value]) => {
                  const first = Array.isArray(value) ? value[0] : value;
                  return /^[a-zA-Z0-9_.[\]-]{1,100}$/.test(key) &&
                      typeof first === 'string'
                      ? [[key, first.slice(0, 1000)]]
                      : [];
              }),
          )
        : {};
const readCleanup = (value: unknown) =>
    draftRecord(value) &&
    ['deleted', 'failed'].every(
        (key) =>
            Number.isSafeInteger(value[key]) && (value[key] as number) >= 0,
    )
        ? { deleted: value.deleted as number, failed: value.failed as number }
        : null;

/** One actor/purpose/context and one revision command at a time. Content stays in memory. */
export function useItTicketDraft({
    enabled = false,
    active = true,
    actorId,
    context,
    onAccessLost,
    workingSnapshot,
    workingDirty,
    workingFiles,
    workingPendingComment,
    workingOutcomeUnknown = false,
    workingSettledOperationToken = 0,
    acceptedFields,
    acceptSelectedFiles,
    acceptsSnapshot,
    timeoutMs = 30000,
}: {
    enabled?: boolean;
    active?: boolean;
    actorId?: number;
    context: ItDraftContext;
    onAccessLost?: () => void;
    workingSnapshot?: ItDraftSnapshot;
    workingDirty?: boolean;
    workingFiles?: File[];
    workingPendingComment?: Readonly<ItCommentIntent> | null;
    workingOutcomeUnknown?: boolean;
    workingSettledOperationToken?: number;
    acceptedFields?: readonly string[];
    acceptSelectedFiles?: boolean;
    acceptsSnapshot?: (snapshot: ItDraftSnapshot) => boolean;
    timeoutMs?: number;
}) {
    const allowed = enabled && active && validDraftContext(actorId, context);
    const scope = draftScopeKey(actorId, context);
    const renderScope = useRef(scope);
    renderScope.current = scope;
    const scopeRef = useRef(scope);
    const [state, setState] = useState<ClientSnapshot>(() => empty(allowed));
    const stateRef = useRef(state);
    const accessLost = useRef(onAccessLost);
    accessLost.current = onAccessLost;
    const mounted = useRef(true);
    const epoch = useRef(0);
    const requestRef = useRef<AbortController | null>(null);
    const frozen = useRef<FrozenOperation | null>(null);
    const submitted = useRef<{
        uuid: string;
        revision: number;
        key: string;
    } | null>(null);
    const reviewedRevision = useRef<number | undefined>(undefined);
    const recoveredSubmission = useRef<Readonly<ItCommentIntent> | null>(null);
    const settledOperationToken = useRef(0);
    const restoredCurrentVersion = useRef<number | null>(null);
    const memoryRef = useRef<ReturnType<typeof useItTicketDraftMemory> | null>(
        null,
    );
    const abortCurrentRequest = useCallback(() => {
        epoch.current++;
        requestRef.current?.abort();
        requestRef.current = null;
    }, []);
    const update = useCallback((change: Partial<ClientSnapshot>) => {
        stateRef.current = { ...stateRef.current, ...change };
        if (mounted.current) setState(stateRef.current);
    }, []);
    const clear = useCallback(
        (stateName: ItDraftClientState, message: string | null = null) => {
            frozen.current = null;
            submitted.current = null;
            recoveredSubmission.current = null;
            update({ ...empty(false), state: stateName, message });
        },
        [update],
    );
    const normalize = useCallback(
        (snapshot: ItDraftSnapshot): ItDraftSnapshot | null => {
            const payload = readDraftPayload(snapshot, context.purpose);
            if (
                !payload ||
                (snapshot.base_ticket_version != null &&
                    (!Number.isSafeInteger(snapshot.base_ticket_version) ||
                        snapshot.base_ticket_version < 1))
            )
                return null;
            return {
                ...payload,
                ...(context.ticketId !== undefined
                    ? {
                          base_ticket_version:
                              snapshot.base_ticket_version ??
                              stateRef.current.draft?.base_ticket_version ??
                              null,
                      }
                    : {}),
            };
        },
        [context.purpose, context.ticketId],
    );
    const unknown = useCallback(
        (message: string) =>
            update({
                state: 'outcome_unknown',
                message,
                retryable: frozen.current !== null,
            }),
        [update],
    );
    const accept = useCallback(
        (draft: ItDraftMetadata, extra: Partial<ClientSnapshot> = {}) => {
            frozen.current = null;
            update({
                draft,
                current: null,
                reviewed: null,
                state: draft.state === 'active' ? 'ready' : 'terminal',
                message: draft.blocker?.message ?? null,
                errors: {},
                retryable: false,
                ...extra,
            });
        },
        [update],
    );

    const pendingCommand = frozen.current;
    const memory = useItTicketDraftMemory({
        enabled:
            active &&
            validDraftContext(actorId, context) &&
            state.state !== 'access_denied',
        persistenceEnabled: enabled,
        actorId,
        context,
        draft: state.draft,
        workingSnapshot,
        workingDirty,
        workingFiles,
        pendingComment: workingPendingComment ?? undefined,
        savedSnapshotKey: state.lastSavedKey,
        settledOperationToken:
            settledOperationToken.current + workingSettledOperationToken,
        acceptedFields,
        acceptSelectedFiles,
        acceptsSnapshot,
        pendingSave:
            pendingCommand?.kind === 'save' && pendingCommand.snapshot
                ? {
                      snapshot: pendingCommand.snapshot,
                      revision: pendingCommand.revision,
                  }
                : undefined,
        pendingUpload:
            pendingCommand?.kind === 'upload' &&
            pendingCommand.file &&
            pendingCommand.uploadUuid
                ? {
                      file: pendingCommand.file,
                      uploadUuid: pendingCommand.uploadUuid,
                      revision: pendingCommand.revision,
                  }
                : undefined,
        outcomeUnknown:
            !!workingPendingComment ||
            workingOutcomeUnknown ||
            state.browserOutcomeUnknown ||
            pendingCommand !== null,
        explicitlyReviewedRevision: reviewedRevision.current,
        canRecover:
            !requestRef.current && !pendingCommand && !workingOutcomeUnknown,
        timeoutMs,
        onAccessLost: () => {
            memoryRef.current?.clearCurrentScope();
            clear('access_denied', messageFor('access_unavailable'));
            accessLost.current?.();
        },
        onSessionExpired: () =>
            update({
                state: 'session_expired',
                reviewed: null,
                current: null,
                message: messageFor('session_expired'),
            }),
    });
    memoryRef.current = memory;

    const execute = useCallback(
        async (
            operation: Operation,
            command?: FrozenOperation,
            readUuid?: string,
        ): Promise<{
            body: Record<string, unknown>;
            draft: ItDraftMetadata;
        } | null> => {
            if (
                !allowed ||
                requestRef.current ||
                memoryRef.current?.busy ||
                renderScope.current !== scope
            )
                return null;
            const controller = new AbortController();
            requestRef.current = controller;
            const token = ++epoch.current;
            if (command) frozen.current = command;
            update({
                pending: operation,
                state:
                    operation === 'check'
                        ? 'checking'
                        : operation === 'resume' || operation === 'review'
                          ? 'reviewing'
                          : 'saving',
                message: null,
                errors: {},
                retryable: false,
            });
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            const same = () =>
                mounted.current &&
                epoch.current === token &&
                renderScope.current === scope;
            try {
                const uuid =
                    command?.uuid ??
                    readUuid ??
                    stateRef.current.draft?.draft_uuid;
                let method: 'post' | 'patch' | 'delete' = 'post';
                let url = '/it/drafts/context';
                let data: Record<string, unknown> | FormData = {
                    actor_user_id: actorId,
                };
                if (operation === 'check')
                    data = {
                        actor_user_id: actorId,
                        purpose: context.purpose,
                        ...(context.ticketId !== undefined
                            ? { ticket_id: context.ticketId }
                            : {
                                  request_uuid:
                                      context.requestUuid.toLowerCase(),
                              }),
                    };
                else if (!uuid) throw new Error('Missing draft generation');
                else if (operation === 'resume' || operation === 'review')
                    url = `/it/drafts/${uuid}/resume`;
                else if (operation === 'save') {
                    method = 'patch';
                    url = `/it/drafts/${uuid}`;
                    data = {
                        actor_user_id: actorId,
                        expected_revision: command!.revision,
                        ...command!.snapshot,
                    };
                } else if (operation === 'discard') {
                    method = 'delete';
                    url = `/it/drafts/${uuid}`;
                    data = {
                        actor_user_id: actorId,
                        expected_revision: command!.revision,
                    };
                } else if (operation === 'start_new') {
                    url = `/it/drafts/${uuid}/start-new`;
                    data = {
                        actor_user_id: actorId,
                        expected_revision: command!.revision,
                    };
                } else if (operation === 'remove') {
                    method = 'delete';
                    url = `/it/drafts/${uuid}/attachments/${command!.attachmentId}`;
                    data = {
                        actor_user_id: actorId,
                        expected_revision: command!.revision,
                    };
                } else {
                    url = `/it/drafts/${uuid}/attachments`;
                    const form = new FormData();
                    form.set('actor_user_id', String(actorId));
                    form.set('expected_revision', String(command!.revision));
                    form.set('upload_uuid', command!.uploadUuid!);
                    form.set('attachment', command!.file!);
                    data = form;
                }
                const response = await axios.request({
                    url,
                    method,
                    data,
                    signal: controller.signal,
                    timeout: timeoutMs,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!same()) return null;
                if (response.status !== 200 || !draftRecord(response.data))
                    throw new Error('Invalid draft acknowledgement');
                const metadata = readDraftMetadata(
                    response.data.draft,
                    context,
                );
                if (
                    !metadata ||
                    (operation !== 'check' &&
                        operation !== 'start_new' &&
                        metadata.draft_uuid !== uuid)
                )
                    throw new Error('Mismatched draft acknowledgement');
                return { body: response.data, draft: metadata };
            } catch (error) {
                if (!same()) return null;
                const response = axios.isAxiosError(error)
                    ? error.response
                    : undefined;
                const body = draftRecord(response?.data) ? response.data : {};
                const status = response?.status;
                const code =
                    typeof body.code === 'string'
                        ? body.code
                        : status === 401 || status === 419
                          ? 'session_expired'
                          : status === 403
                            ? 'access_unavailable'
                            : status === 404
                              ? 'draft_unavailable'
                              : 'draft_request_failed';
                // A successful start-new rotates the old UUID. A lost acknowledgement
                // can therefore produce 404 on its exact retry; only context lookup can reconcile it.
                if (status === 404 && operation === 'start_new')
                    unknown(
                        'The old generation is unavailable. Check this draft context to recover the new generation.',
                    );
                else if (status === 403 || status === 404) {
                    memoryRef.current?.clearCurrentScope();
                    clear('access_denied', messageFor(code));
                    accessLost.current?.();
                } else if (code === 'drafts_disabled') {
                    frozen.current = null;
                    update({
                        state: 'disabled',
                        message: messageFor(code),
                        retryable: false,
                    });
                } else if (status === 401 || status === 419)
                    update({
                        state: 'session_expired',
                        reviewed: null,
                        current: null,
                        message: messageFor('session_expired'),
                        retryable: false,
                    });
                else if (status === 422) {
                    if (command) settledOperationToken.current++;
                    frozen.current = null;
                    update({
                        state: 'ready',
                        message: messageFor('draft_validation'),
                        errors: errorFields(body),
                        retryable: false,
                    });
                } else if (status === 409)
                    update({
                        state: 'conflict',
                        current: readDraftMetadata(body.current, context),
                        reviewed: null,
                        message: messageFor(code),
                        retryable: false,
                    });
                else unknown(messageFor(code));
                return null;
            } finally {
                clearTimeout(timer);
                if (epoch.current === token) {
                    requestRef.current = null;
                    if (mounted.current) update({ pending: null });
                }
            }
        },
        [allowed, actorId, context, scope, timeoutMs, update, clear, unknown],
    );

    const check = useCallback(async () => {
        const pending = frozen.current;
        const previous = stateRef.current.draft;
        const result = await execute('check');
        if (!result) return null;
        if (result.draft.state !== 'active') {
            accept(result.draft, { attachments: [], lastSavedKey: null });
        } else if (
            pending?.kind === 'start_new' &&
            result.draft.draft_uuid !== pending.uuid
        ) {
            // Metadata confirms a replacement exists, never that it is still empty.
            // Require explicit resume before any write to this generation.
            accept(result.draft, {
                attachments: [],
                lastSavedKey: null,
                state: 'available',
                message:
                    'A new draft generation is available. Resume it before continuing.',
            });
        } else if (pending) {
            update({
                current: result.draft,
                state: 'outcome_unknown',
                retryable: true,
                message:
                    'The earlier command still needs an exact retry or explicit review of the saved draft.',
            });
        } else if (
            previous &&
            (previous.draft_uuid !== result.draft.draft_uuid ||
                previous.revision !== result.draft.revision)
        ) {
            update({
                current: result.draft,
                state: 'conflict',
                retryable: false,
                message: messageFor('draft_conflict'),
            });
        } else
            accept(result.draft, {
                state:
                    stateRef.current.lastSavedKey !== null
                        ? 'ready'
                        : result.draft.has_content
                          ? 'available'
                          : 'ready',
            });
        return result.draft;
    }, [execute, accept, update]);

    const read = useCallback(
        async (reviewOnly: boolean) => {
            const target = stateRef.current.current ?? stateRef.current.draft;
            if (!target?.capabilities.read || target.state !== 'active')
                return null;
            const result = await execute(
                reviewOnly ? 'review' : 'resume',
                undefined,
                target.draft_uuid,
            );
            if (!result) return null;
            const payload = readDraftPayload(
                result.body.payload,
                context.purpose,
            );
            const files = Array.isArray(result.body.attachments)
                ? result.body.attachments.map(readDraftAttachment)
                : null;
            if (
                result.draft.state !== 'active' ||
                !payload ||
                !files ||
                files.length > 5 ||
                files.some((file) => !file) ||
                new Set(files.map((file) => file?.id)).size !== files.length ||
                new Set(files.map((file) => file?.upload_uuid)).size !==
                    files.length
            ) {
                unknown(
                    'The saved draft could not be verified. Your current text is retained.',
                );
                return null;
            }
            const resumed = {
                draft: result.draft,
                payload,
                attachments: files as ItDraftAttachment[],
            };
            if (reviewOnly)
                update({
                    current: result.draft,
                    reviewed: resumed,
                    state: 'conflict',
                    retryable: false,
                    message:
                        'Compare the saved draft with your current work before choosing which version to use.',
                });
            else
                accept(result.draft, {
                    attachments: resumed.attachments,
                    lastSavedKey: draftSnapshotKey({
                        ...payload,
                        ...(context.ticketId !== undefined
                            ? {
                                  base_ticket_version:
                                      result.draft.base_ticket_version,
                              }
                            : {}),
                    }),
                });
            return resumed;
        },
        [execute, context, accept, update, unknown],
    );

    const mutate = useCallback(
        async (command: FrozenOperation) => {
            const result = await execute(command.kind, command);
            if (!result) return false;
            const { draft, body } = result;
            if (command.kind === 'start_new') {
                if (
                    draft.state !== 'active' ||
                    draft.draft_uuid === command.uuid ||
                    draft.revision !== command.revision + 1 ||
                    draft.has_content
                ) {
                    unknown(
                        'The new draft generation could not be confirmed. Check its current state.',
                    );
                    return false;
                }
                accept(draft, {
                    attachments: [],
                    lastSavedKey: null,
                    message: draft.cleanup?.failed
                        ? 'The new draft is ready. Previous private-file cleanup is still pending.'
                        : null,
                });
                return true;
            }
            if (command.kind === 'discard') {
                if (
                    draft.state !== 'discarded' ||
                    ![command.revision, command.revision + 1].includes(
                        draft.revision,
                    )
                ) {
                    unknown(
                        'Discarding this draft could not be confirmed. Check its current state.',
                    );
                    return false;
                }
                accept(draft, {
                    attachments: [],
                    lastSavedKey: null,
                    message: draft.cleanup?.failed
                        ? 'The draft is discarded. Private file cleanup is still pending.'
                        : 'The draft is discarded.',
                });
                memoryRef.current?.purgeGeneration(command.uuid);
                return true;
            }
            const maximum =
                command.kind === 'upload'
                    ? command.revision + 2
                    : command.revision + 1;
            if (
                draft.state !== 'active' ||
                draft.revision < command.revision ||
                draft.revision > maximum
            ) {
                update({
                    current: draft,
                    state: 'conflict',
                    retryable: false,
                    message:
                        'The command returned a newer draft revision. Review it before continuing.',
                });
                return false;
            }
            if (command.kind === 'save') {
                accept(draft, {
                    lastSavedKey: draftSnapshotKey(command.snapshot!),
                });
                settledOperationToken.current++;
                return true;
            }
            if (command.kind === 'upload') {
                const attachment = readDraftAttachment(body.attachment);
                if (
                    !attachment ||
                    attachment.upload_uuid !== command.uploadUuid ||
                    attachment.name !== command.file?.name ||
                    attachment.size !== command.file?.size ||
                    attachment.state !== 'ready'
                ) {
                    unknown(
                        'The uploaded file could not be confirmed. Keep the selected file for an exact retry.',
                    );
                    return false;
                }
                accept(draft, {
                    attachments: [
                        ...stateRef.current.attachments.filter(
                            (file) => file.id !== attachment.id,
                        ),
                        attachment,
                    ],
                });
                settledOperationToken.current++;
                return true;
            }
            const cleanup = readCleanup(body.cleanup);
            if (
                body.removed_attachment_id !== command.attachmentId ||
                !cleanup
            ) {
                unknown(
                    'Removing this file could not be confirmed. Check the saved draft before retrying.',
                );
                return false;
            }
            accept(draft, {
                attachments: stateRef.current.attachments.filter(
                    (file) => file.id !== command.attachmentId,
                ),
                message: cleanup.failed
                    ? 'The file was removed from the draft. Private file cleanup is still pending.'
                    : 'The file was removed from the draft.',
            });
            return true;
        },
        [execute, accept, update, unknown],
    );

    const canMutate = useCallback(
        () =>
            allowed &&
            !requestRef.current &&
            !memoryRef.current?.busy &&
            !memoryRef.current?.notices.length &&
            !stateRef.current.browserOutcomeUnknown &&
            stateRef.current.browserBlocker === null &&
            !frozen.current &&
            stateRef.current.state === 'ready',
        [allowed],
    );
    const save = useCallback(
        async (snapshot: ItDraftSnapshot) => {
            const draft = stateRef.current.draft;
            const safe = normalize(snapshot);
            if (!canMutate() || !draft?.capabilities.save) return false;
            if (!safe) {
                update({
                    message:
                        'These details cannot be saved as this type of draft. Review the form fields.',
                    errors: { draft: 'The draft contains invalid fields.' },
                });
                return false;
            }
            return mutate({
                kind: 'save',
                uuid: draft.draft_uuid,
                revision: draft.revision,
                snapshot: structuredClone(safe),
            });
        },
        [normalize, canMutate, mutate, update],
    );
    const terminal = useCallback(
        async (kind: 'discard' | 'start_new') => {
            const draft = stateRef.current.draft;
            if (
                !allowed ||
                requestRef.current ||
                frozen.current ||
                !draft ||
                !['ready', 'available', 'terminal'].includes(
                    stateRef.current.state,
                ) ||
                !draft.capabilities[
                    kind === 'discard' ? 'discard' : 'start_new'
                ]
            )
                return false;
            return mutate({
                kind,
                uuid: draft.draft_uuid,
                revision: draft.revision,
            });
        },
        [allowed, mutate],
    );
    const upload = useCallback(
        async (file: File) => {
            const draft = stateRef.current.draft;
            if (
                !canMutate() ||
                !draft?.capabilities.save ||
                ![
                    'requester_intake',
                    'technician_intake',
                    'public_reply',
                    'internal_note',
                ].includes(context.purpose)
            )
                return false;
            if (
                file.size > 10 * 1024 * 1024 ||
                stateRef.current.attachments.length >= 5
            ) {
                update({
                    message:
                        'Attach up to five files, each no larger than 10 MB.',
                    errors: Object.fromEntries(
                        Object.entries(stateRef.current.errors).filter(
                            ([field]) => field !== 'attachment',
                        ),
                    ),
                });
                return false;
            }
            if (!isAllowedItAttachmentName(file.name)) {
                update({
                    message:
                        'Choose an image, PDF, text, CSV, Word or Excel file. Your draft and existing files have been kept.',
                    errors: Object.fromEntries(
                        Object.entries(stateRef.current.errors).filter(
                            ([field]) => field !== 'attachment',
                        ),
                    ),
                });
                return false;
            }
            let uuid: string;
            try {
                uuid = crypto.randomUUID();
            } catch {
                update({
                    message:
                        'A secure upload identity is unavailable. Keep the selected file and refresh this secure page before uploading.',
                });
                return false;
            }
            if (!IT_DRAFT_UUID.test(uuid)) return false;
            return mutate({
                kind: 'upload',
                uuid: draft.draft_uuid,
                revision: draft.revision,
                file,
                uploadUuid: uuid,
            });
        },
        [canMutate, context.purpose, mutate, update],
    );
    const remove = useCallback(
        async (attachmentId: number) => {
            const draft = stateRef.current.draft;
            if (
                !canMutate() ||
                !draft?.capabilities.save ||
                !stateRef.current.attachments.some(
                    (file) => file.id === attachmentId,
                )
            )
                return false;
            return mutate({
                kind: 'remove',
                uuid: draft.draft_uuid,
                revision: draft.revision,
                attachmentId,
            });
        },
        [canMutate, mutate],
    );
    const retry = useCallback(async () => {
        const command = frozen.current;
        if (!command || !stateRef.current.retryable || requestRef.current)
            return false;
        if (command.kind === 'start_new') {
            const current = await check();
            if (!current || current.draft_uuid !== command.uuid) return false;
            // A terminal metadata check clears frozen intent; the original command
            // is still the only permissible retry for this exact generation.
        }
        return mutate(command);
    }, [check, mutate]);
    const adoptReviewed = useCallback(() => {
        const reviewed = stateRef.current.reviewed;
        if (
            !reviewed ||
            reviewed.draft.state !== 'active' ||
            requestRef.current
        )
            return false;
        const pendingUpload =
            frozen.current?.kind === 'upload' ? frozen.current : null;
        const uploaded = pendingUpload
            ? reviewed.attachments.find(
                  (file) =>
                      file.upload_uuid === pendingUpload.uploadUuid &&
                      file.name === pendingUpload.file?.name &&
                      file.size === pendingUpload.file?.size &&
                      file.state === 'ready',
              )
            : null;
        reviewedRevision.current = reviewed.draft.revision;
        // The host keeps its own proposed text. Adopting a token never posts it.
        accept(reviewed.draft, {
            attachments: reviewed.attachments,
            lastSavedKey: null,
        });
        if (pendingUpload && !uploaded) {
            frozen.current = pendingUpload;
            update({
                reviewed,
                state: 'outcome_unknown',
                retryable: true,
                message:
                    'The reviewed draft still needs the selected upload. Retry the original file, or explicitly release it before choosing another.',
            });
        }
        return true;
    }, [accept, update]);
    const releasePendingUpload = useCallback(() => {
        const reviewed = stateRef.current.reviewed;
        if (
            !reviewed ||
            requestRef.current ||
            frozen.current?.kind !== 'upload'
        )
            return false;
        accept(reviewed.draft, {
            attachments: reviewed.attachments,
            lastSavedKey: null,
            message:
                'The selected file was released from this browser. Any incomplete saved file remains listed; remove it before submitting.',
        });
        return true;
    }, [accept]);
    const cancel = useCallback(() => {
        requestRef.current?.abort();
        memoryRef.current?.cancel();
    }, []);
    const isSaved = useCallback(
        (snapshot: ItDraftSnapshot) => {
            const safe = normalize(snapshot);
            return (
                !!safe &&
                stateRef.current.lastSavedKey === draftSnapshotKey(safe)
            );
        },
        [normalize],
    );
    const submissionReference = useCallback(
        (snapshot: ItDraftSnapshot): ItDraftCommitReference | null => {
            const draft = stateRef.current.draft;
            const safe = normalize(snapshot);
            if (
                !canMutate() ||
                !actorId ||
                !draft?.capabilities.submit ||
                !safe ||
                !isSaved(safe)
            )
                return null;
            submitted.current = {
                uuid: draft.draft_uuid,
                revision: draft.revision,
                key: draftSnapshotKey(safe),
            };
            return {
                draft_uuid: draft.draft_uuid,
                draft_revision: draft.revision,
                draft_actor_user_id: actorId,
            };
        },
        [canMutate, actorId, normalize, isSaved],
    );
    const acknowledgeConsumed = useCallback(
        (
            value: unknown,
            currentSnapshot: ItDraftSnapshot,
            options: { preserveLocal?: boolean } = {},
        ) => {
            const sent = submitted.current;
            if (
                !sent ||
                !draftRecord(value) ||
                value.draft_uuid !== sent.uuid ||
                value.submitted_revision !== sent.revision ||
                value.state !== 'consumed' ||
                value.revision !== sent.revision + 1
            )
                return false;
            const safe = normalize(currentSnapshot);
            const clearBuffer =
                options.preserveLocal !== true &&
                !!safe &&
                draftSnapshotKey(safe) === sent.key;
            if (safe && clearBuffer)
                memoryRef.current?.acknowledgeConsumed({
                    draftUuid: sent.uuid,
                    revision: sent.revision,
                    snapshot: safe,
                });
            // Do not fabricate capability metadata from a commit acknowledgement.
            // An explicit context check loads the canonical terminal/start-new state.
            clear(
                'terminal',
                clearBuffer
                    ? 'This draft was submitted successfully.'
                    : 'The submitted draft is saved. Your newer local changes remain unsaved.',
            );
            return clearBuffer;
        },
        [normalize, clear],
    );

    /** Register only the exact submitted snapshot restored by fresh RAM authorization. */
    const registerRecoveredSubmission = (
        snapshot: ItDraftSnapshot,
        reference: Readonly<ItDraftCommitReference>,
    ): boolean => {
        const command = recoveredSubmission.current;
        const safe = normalize(snapshot);
        if (
            !command?.draft ||
            !safe ||
            renderScope.current !== scopeRef.current ||
            command.actorId !== actorId ||
            command.ticketId !== context.ticketId ||
            context.purpose !==
                (command.isInternal ? 'internal_note' : 'public_reply') ||
            safe.fields.body !== command.body ||
            safe.base_ticket_version !== command.expectedVersion ||
            reference.draft_actor_user_id !== command.actorId ||
            reference.draft_uuid !== command.draft.draft_uuid ||
            reference.draft_revision !== command.draft.draft_revision
        )
            return false;
        submitted.current = {
            uuid: reference.draft_uuid,
            revision: reference.draft_revision,
            key: draftSnapshotKey(safe),
        };
        return true;
    };

    const resumeMemory = async (
        bufferId: string,
    ): Promise<ItDraftBrowserRestored | null> => {
        if (requestRef.current || frozen.current || workingOutcomeUnknown)
            return null;
        const restored = await memory.resume(bufferId);
        if (!restored) return null;
        const { candidate, serverResumed, localAuthorization } = restored;
        recoveredSubmission.current = candidate.pendingComment ?? null;
        restoredCurrentVersion.current =
            localAuthorization?.current_ticket_version ??
            serverResumed?.draft.current_ticket_version ??
            null;
        if (serverResumed) {
            accept(serverResumed.draft, {
                attachments: serverResumed.attachments,
                lastSavedKey: draftSnapshotKey({
                    ...serverResumed.payload,
                    ...(context.ticketId !== undefined
                        ? {
                              base_ticket_version:
                                  serverResumed.draft.base_ticket_version,
                          }
                        : {}),
                }),
            });
        } else
            update({
                state: allowed
                    ? stateRef.current.draft?.has_content
                        ? 'available'
                        : 'ready'
                    : 'disabled',
                current: null,
                reviewed: null,
                message: null,
                errors: {},
                retryable: false,
            });
        if (candidate.kind !== 'memory' && candidate.pendingSave)
            frozen.current = {
                kind: 'save',
                uuid: candidate.draftUuid,
                revision: candidate.pendingSave.revision,
                snapshot: candidate.pendingSave.snapshot,
            };
        else if (candidate.kind !== 'memory' && candidate.pendingUpload)
            frozen.current = {
                kind: 'upload',
                uuid: candidate.draftUuid,
                revision: candidate.pendingUpload.revision,
                file: candidate.pendingUpload.file,
                uploadUuid: candidate.pendingUpload.uploadUuid,
            };
        const canonicalUnknown =
            candidate.outcomeUnknown &&
            !candidate.pendingSave &&
            !candidate.pendingUpload;
        const restoredBlocker =
            localAuthorization?.blocker ??
            (localAuthorization?.capabilities.submit === false
                ? {
                      code: 'submission_unavailable',
                      message:
                          'This restored work cannot currently be submitted. Review the current record before continuing.',
                  }
                : null);
        update({
            browserOutcomeUnknown: canonicalUnknown,
            browserBlocker: restoredBlocker,
            ...(candidate.outcomeUnknown
                ? {
                      state: 'outcome_unknown' as const,
                      retryable: frozen.current !== null,
                      message: frozen.current
                          ? 'Your browser work is restored. The original draft command still needs its exact retry or explicit review.'
                          : 'Your browser work is restored, but its earlier submission is still unconfirmed. Review the current record before any further submission.',
                  }
                : {
                      message:
                          restoredBlocker?.message ??
                          'Your browser work is restored. These changes have not been confirmed as saved.',
                  }),
        });
        return {
            snapshot: candidate.snapshot,
            ...(candidate.pendingComment
                ? { pendingComment: candidate.pendingComment }
                : {}),
            files:
                candidate.kind === 'memory'
                    ? (candidate.selectedFiles ?? [])
                    : [],
            outcomeUnknown: candidate.outcomeUnknown,
            canonicalOutcomeUnknown: canonicalUnknown,
            current_ticket_version:
                localAuthorization?.current_ticket_version ??
                serverResumed?.draft.current_ticket_version ??
                null,
            blocker: restoredBlocker ?? serverResumed?.draft.blocker ?? null,
        };
    };

    useEffect(() => {
        mounted.current = true;
        if (scopeRef.current !== scope) {
            abortCurrentRequest();
            scopeRef.current = scope;
            reviewedRevision.current = undefined;
            clear(allowed ? 'idle' : 'disabled');
        }
        if (!allowed) {
            abortCurrentRequest();
            clear('disabled');
            return;
        }
        void check();
        return abortCurrentRequest;
        // Stable context identity, not a newly allocated context object, governs lifetime.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scope, allowed, abortCurrentRequest]);
    useEffect(
        () => () => {
            mounted.current = false;
            requestRef.current?.abort();
            frozen.current = null;
            submitted.current = null;
            recoveredSubmission.current = null;
        },
        [],
    );
    const visible =
        renderScope.current === scopeRef.current ? state : empty(allowed);
    return {
        ...visible,
        persistenceEnabled: allowed,
        busy: visible.pending !== null || memory.busy,
        memoryNotices: memory.notices,
        memoryWarning: memory.warning,
        memoryFailure: memory.failure,
        memoryBlocked:
            memory.notices.length > 0 ||
            memory.busy ||
            visible.browserOutcomeUnknown ||
            visible.browserBlocker !== null,
        resumeMemory,
        discardMemory: memory.discardLocal,
        acknowledgeReviewedBrowserWork: (currentVersion: number) => {
            if (
                requestRef.current ||
                frozen.current ||
                memory.busy ||
                !Number.isSafeInteger(currentVersion) ||
                restoredCurrentVersion.current === null ||
                currentVersion < restoredCurrentVersion.current
            )
                return false;
            settledOperationToken.current++;
            update({
                browserOutcomeUnknown: false,
                browserBlocker: null,
                message: null,
                state: allowed
                    ? stateRef.current.draft?.has_content &&
                      stateRef.current.lastSavedKey === null
                        ? 'available'
                        : 'ready'
                    : 'disabled',
            });
            return true;
        },
        clearBrowserWork: () => {
            memory.clearCurrentScope();
            settledOperationToken.current++;
            update({ browserOutcomeUnknown: false, browserBlocker: null });
        },
        clearOwnedBrowserWork: () => {
            memory.clearOwnedWork();
            settledOperationToken.current++;
            update({ browserOutcomeUnknown: false, browserBlocker: null });
        },
        check,
        resume: () => read(false),
        review: () => read(true),
        save,
        discard: () => terminal('discard'),
        startNew: () => terminal('start_new'),
        upload,
        remove,
        retry,
        adoptReviewed,
        canReleaseUpload:
            frozen.current?.kind === 'upload' && visible.reviewed !== null,
        releasePendingUpload,
        cancel,
        isSaved,
        submissionReference,
        registerRecoveredSubmission,
        acknowledgeConsumed,
    };
}
export type ItTicketDraftClient = ReturnType<typeof useItTicketDraft>;

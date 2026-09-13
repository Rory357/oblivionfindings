import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    freezeItCommentIntent,
    itCommentFormData,
    newItCommentUuid,
    readItCommentAccessProof,
    readItCommentCancelled,
    readItCommentCommitted,
    type ItCommentCancelled,
    type ItCommentCommitted,
    type ItCommentIdentity,
    type ItCommentIntent,
} from './it-ticket-comment-contract';
import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

type Stage =
    | 'editing'
    | 'sending'
    | 'recovering'
    | 'checking_access'
    | 'cancelling'
    | 'unknown'
    | 'session'
    | 'access'
    | 'rejected'
    | 'committed';
interface State {
    stage: Stage;
    message: string | null;
    errors: Record<string, string>;
    references: string[];
    requestUuid: string | null;
    result: ItCommentCommitted | null;
    cancelled: ItCommentCancelled | null;
    accessConcealed: boolean;
    settledOperationToken: number;
    accessBlocker: { code: string; message: string } | null;
}
const empty = (): State => ({
    stage: 'editing',
    message: null,
    errors: {},
    references: [],
    requestUuid: null,
    result: null,
    cancelled: null,
    accessConcealed: false,
    settledOperationToken: 0,
    accessBlocker: null,
});
const positive = (value: number | undefined): value is number =>
    Number.isSafeInteger(value) && value! > 0;
const receiptOnly = (value: ItCommentIdentity): ItCommentIdentity => ({
    actorId: value.actorId,
    ticketId: value.ticketId,
    requestUuid: value.requestUuid,
    isInternal: value.isInternal,
    ...(value.expectedVersion !== undefined
        ? { expectedVersion: value.expectedVersion }
        : {}),
    ...(value.draft ? { draft: { ...value.draft } } : {}),
});

/** Only opaque command UUIDs survive a document reload; never body, files or private field values. */
function readReferences(key: string): string[] {
    const raw = sessionStorage.getItem(key);
    if (raw === null) return [];
    const parsed: unknown = JSON.parse(raw);
    if (
        !Array.isArray(parsed) ||
        parsed.length > 20 ||
        parsed.some(
            (value) => typeof value !== 'string' || !IT_DRAFT_UUID.test(value),
        )
    ) {
        throw new Error(
            'The pending reply references need review before another reply can be submitted.',
        );
    }
    return [...new Set(parsed as string[])];
}
function updateReferences(key: string, uuid: string, add: boolean): string[] {
    const previous = readReferences(key);
    const next = add
        ? [...new Set([...previous, uuid])]
        : previous.filter((value) => value !== uuid);
    if (next.length > 20)
        throw new Error(
            'Review pending reply outcomes before starting another reply.',
        );
    if (next.length) sessionStorage.setItem(key, JSON.stringify(next));
    else sessionStorage.removeItem(key);
    return next;
}
function fieldErrors(value: unknown): Record<string, string> {
    if (!draftRecord(value) || !draftRecord(value.errors)) return {};
    return Object.fromEntries(
        Object.entries(value.errors).flatMap(([key, messages]) => {
            if (
                !/^(body|is_internal|attachments(?:\.\d+)?|expected_version|draft_[a-z_]+)$/.test(
                    key,
                )
            )
                return [];
            const message = Array.isArray(messages)
                ? messages.find((item) => typeof item === 'string')
                : messages;
            return typeof message === 'string'
                ? [[key, message.slice(0, 1000)]]
                : [];
        }),
    );
}

/** Reply transport only. Canonical services own writes; the composer owns audience-separated work. */
export function useItTicketCommentCommand({
    actorId,
    ticketId,
    isInternal,
    onCommitted,
    onAccessLost,
}: {
    actorId: number | undefined;
    ticketId: number;
    isInternal: boolean;
    onCommitted: (result: ItCommentCommitted) => void;
    onAccessLost: () => void;
}) {
    const scope = `${actorId ?? 'none'}:${ticketId}:${isInternal ? 'internal' : 'public'}`;
    const key = `it.pending-comment-command.v1.${scope}`;
    const [state, setState] = useState<State>(empty);
    const [renderScope, setRenderScope] = useState(scope);
    const stateRef = useRef(state);
    const currentScope = useRef(scope);
    currentScope.current = scope;
    const callbacks = useRef({ onCommitted, onAccessLost });
    callbacks.current = { onCommitted, onAccessLost };
    const intent = useRef<Readonly<ItCommentIntent> | null>(null);
    const receiptIdentity = useRef<ItCommentIdentity | null>(null);
    const active = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const mounted = useRef(true);
    const uncertain = useRef(false);
    const idempotencyConflict = useRef(false);
    const authorizedVersion = useRef<number | null>(null);
    const reported = useRef(new Set<string>());
    const invalidateRequest = useCallback(() => {
        ++epoch.current;
        active.current?.abort();
        active.current = null;
    }, []);
    const update = useCallback((patch: Partial<State>) => {
        if (!mounted.current) return;
        stateRef.current = { ...stateRef.current, ...patch };
        setState(stateRef.current);
    }, []);
    useEffect(() => {
        mounted.current = true;
        invalidateRequest();
        intent.current = null;
        receiptIdentity.current = null;
        uncertain.current = false;
        idempotencyConflict.current = false;
        authorizedVersion.current = null;
        stateRef.current = empty();
        setRenderScope(scope);
        try {
            update({ ...empty(), references: readReferences(key) });
        } catch {
            update({
                ...empty(),
                stage: 'rejected',
                message:
                    'Pending reply references are unavailable. Restore browser storage access before submitting; your entered work has not been sent.',
            });
        }
        return () => {
            mounted.current = false;
            invalidateRequest();
            intent.current = null;
            receiptIdentity.current = null;
        };
    }, [scope, key, update, invalidateRequest]);

    const execute = useCallback(
        async (recover: boolean, cancel = false) => {
            const identity = receiptIdentity.current;
            if (!identity || active.current || currentScope.current !== scope)
                return;
            const frozen = intent.current;
            if (!recover && !frozen) return;
            const controller = new AbortController();
            active.current = controller;
            const requestEpoch = ++epoch.current;
            const stillCurrent = () =>
                mounted.current &&
                requestEpoch === epoch.current &&
                currentScope.current === scope;
            update({
                stage: cancel
                    ? 'cancelling'
                    : recover
                      ? 'recovering'
                      : 'sending',
                errors: {},
                message: null,
            });
            const unknown = (message: string) => {
                uncertain.current = true;
                update({ stage: 'unknown', message });
            };
            try {
                const response = cancel
                    ? await axios.post(
                          `/it/tickets/${identity.ticketId}/comment-commands/${identity.requestUuid}/cancel`,
                          {
                              actor_user_id: identity.actorId,
                              is_internal: identity.isInternal,
                          },
                          {
                              signal: controller.signal,
                              timeout: 30000,
                              headers: {
                                  Accept: 'application/json',
                                  'X-Requested-With': 'XMLHttpRequest',
                              },
                          },
                      )
                    : recover
                      ? await axios.get(
                            `/it/tickets/${identity.ticketId}/comment-commands/${identity.requestUuid}`,
                            {
                                params: { actor_user_id: identity.actorId },
                                signal: controller.signal,
                                timeout: 30_000,
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            },
                        )
                      : await axios.post(
                            `/it/tickets/${identity.ticketId}/comments`,
                            itCommentFormData(frozen!),
                            {
                                signal: controller.signal,
                                timeout: 30_000,
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            },
                        );
                if (!stillCurrent()) return;
                const cancelled =
                    response.status === 200
                        ? readItCommentCancelled(response.data, identity)
                        : null;
                if (cancelled) {
                    let references = stateRef.current.references;
                    try {
                        references = updateReferences(
                            key,
                            identity.requestUuid,
                            false,
                        );
                    } catch {
                        /* The exact tombstone can safely be recovered again. */
                    }
                    intent.current = null;
                    uncertain.current = false;
                    idempotencyConflict.current = false;
                    authorizedVersion.current = null;
                    update({
                        stage: 'rejected',
                        cancelled,
                        result: null,
                        references,
                        errors: {},
                        accessConcealed: false,
                        settledOperationToken:
                            stateRef.current.settledOperationToken + 1,
                        message:
                            'The earlier reply will not be added. Its saved draft was not discarded. Review the current ticket before preparing another reply.',
                    });
                    return;
                }
                const result = readItCommentCommitted(response.data, identity);
                if (
                    !result ||
                    (recover
                        ? response.status !== 200 || !result.replayed
                        : (response.status !== 200 &&
                              response.status !== 201) ||
                          (response.status === 200) !== result.replayed)
                ) {
                    update({ accessConcealed: true });
                    unknown(
                        'The reply was not confirmed. Check its saved result before changing or resubmitting it.',
                    );
                    return;
                }
                let references = stateRef.current.references;
                try {
                    references = updateReferences(
                        key,
                        identity.requestUuid,
                        false,
                    );
                } catch {
                    /* A confirmed receipt still proves commit; an opaque stale marker can be checked again. */
                }
                uncertain.current = false;
                intent.current = null;
                update({
                    stage: 'committed',
                    result,
                    references,
                    message: null,
                    accessConcealed: false,
                    settledOperationToken:
                        stateRef.current.settledOperationToken + 1,
                });
                // Commit is definitive before the host clears its acknowledged
                // draft and prepares the next reply within this callback.
                active.current = null;
                if (!reported.current.has(result.request_uuid)) {
                    reported.current.add(result.request_uuid);
                    try {
                        callbacks.current.onCommitted(result);
                    } catch {
                        update({
                            message:
                                'Your reply is saved. Refresh the conversation to finish updating this page.',
                        });
                    }
                }
            } catch (error) {
                if (!stillCurrent()) return;
                const response = axios.isAxiosError(error)
                    ? error.response
                    : undefined;
                const status = response?.status;
                if (status === 401 || status === 419) {
                    update({
                        stage: 'session',
                        accessConcealed: true,
                        message:
                            'Sign in with the same account, then check the original reply result. Entered work remains concealed until access is checked.',
                    });
                    return;
                }
                if (status === 403 || (status === 404 && !recover)) {
                    intent.current = null;
                    update({
                        stage: 'access',
                        accessConcealed: true,
                        errors: {},
                        message:
                            'Your access changed. Entered details are concealed; reload before continuing.',
                    });
                    try {
                        callbacks.current.onAccessLost();
                    } catch {
                        /* Denial stays authoritative if the host cannot finish its cleanup. */
                    }
                    return;
                }
                if (
                    status === 409 &&
                    draftRecord(response?.data) &&
                    response.data.code === 'idempotency_conflict'
                ) {
                    idempotencyConflict.current = true;
                    unknown(
                        'This reference belongs to a different original reply. Recover its saved result; it cannot be released for another submission.',
                    );
                    return;
                }
                if (
                    !recover &&
                    !uncertain.current &&
                    (status === 422 || status === 409)
                ) {
                    update({
                        stage: 'rejected',
                        errors: fieldErrors(response?.data),
                        message:
                            status === 409
                                ? 'The reply was rejected because the ticket or command changed. Review the current ticket before preparing another submission.'
                                : 'The reply was not added. Correct the highlighted details; your entered work is retained.',
                    });
                    return;
                }
                if (recover && status === 404)
                    update({ accessConcealed: true });
                unknown(
                    recover && status === 404
                        ? 'No saved result is available yet. That does not prove the earlier reply failed; keep this command for recovery.'
                        : 'The reply outcome is unconfirmed. Check the saved result or retry the exact original reply.',
                );
            } finally {
                if (stillCurrent()) active.current = null;
            }
        },
        [key, scope, update],
    );

    const submit = (
        input: Omit<
            ItCommentIntent,
            'actorId' | 'ticketId' | 'isInternal' | 'requestUuid'
        >,
    ): boolean => {
        if (
            !positive(actorId) ||
            !positive(ticketId) ||
            active.current ||
            intent.current ||
            receiptIdentity.current ||
            currentScope.current !== scope ||
            !['editing', 'rejected'].includes(stateRef.current.stage)
        )
            return false;
        try {
            const references = readReferences(key);
            if (references.length) {
                update({
                    references,
                    message:
                        'Check the pending reply result before starting another submission.',
                });
                return false;
            }
            const requestUuid = newItCommentUuid();
            const frozen = freezeItCommentIntent({
                ...input,
                actorId,
                ticketId,
                isInternal,
                requestUuid,
            });
            // Fail before sending if the opaque recovery reference cannot be retained.
            const recorded = updateReferences(key, requestUuid, true);
            intent.current = frozen;
            receiptIdentity.current = receiptOnly(frozen);
            uncertain.current = false;
            update({ requestUuid, references: recorded });
            void execute(false);
            return true;
        } catch {
            update({
                stage: 'rejected',
                message:
                    'The reply could not be prepared safely. Check the text and files, and allow browser session storage for its recovery reference. Nothing was sent.',
            });
            return false;
        }
    };
    const recoverReference = (requestUuid: string) => {
        if (
            !positive(actorId) ||
            !positive(ticketId) ||
            currentScope.current !== scope ||
            active.current ||
            (intent.current !== null &&
                intent.current.requestUuid !== requestUuid) ||
            !IT_DRAFT_UUID.test(requestUuid) ||
            !stateRef.current.references.includes(requestUuid)
        )
            return;
        receiptIdentity.current = intent.current
            ? receiptOnly(intent.current)
            : {
                  actorId,
                  ticketId,
                  requestUuid,
                  isInternal,
              };
        uncertain.current = true;
        update({ requestUuid });
        void execute(true);
    };
    const stopWaiting = () => {
        if (!active.current || currentScope.current !== scope) return;
        ++epoch.current;
        active.current.abort();
        active.current = null;
        uncertain.current = true;
        update({
            stage: 'unknown',
            message:
                'Stopped waiting. The server request may still finish; check its saved result before making another submission.',
        });
    };
    const cancelReference = async (
        requestUuid = receiptIdentity.current?.requestUuid,
    ): Promise<boolean> => {
        if (
            !positive(actorId) ||
            !positive(ticketId) ||
            !requestUuid ||
            !IT_DRAFT_UUID.test(requestUuid) ||
            active.current ||
            currentScope.current !== scope ||
            (intent.current && intent.current.requestUuid !== requestUuid)
        )
            return false;
        try {
            if (!readReferences(key).includes(requestUuid)) return false;
        } catch {
            return false;
        }
        if (receiptIdentity.current?.requestUuid !== requestUuid)
            receiptIdentity.current = {
                actorId,
                ticketId,
                requestUuid,
                isInternal,
            };
        update({ requestUuid });
        await execute(true, true);
        return (
            stateRef.current.cancelled?.request_uuid === requestUuid ||
            stateRef.current.result?.request_uuid === requestUuid
        );
    };
    const releaseKnownRejection = (reviewedVersion: number): boolean => {
        if (
            stateRef.current.stage !== 'rejected' ||
            currentScope.current !== scope ||
            uncertain.current ||
            active.current ||
            idempotencyConflict.current ||
            stateRef.current.accessConcealed ||
            !positive(reviewedVersion) ||
            reviewedVersion !== authorizedVersion.current
        )
            return false;
        try {
            const id = receiptIdentity.current?.requestUuid;
            const references = id
                ? updateReferences(key, id, false)
                : readReferences(key);
            intent.current = null;
            receiptIdentity.current = null;
            update({
                ...empty(),
                references,
                settledOperationToken:
                    stateRef.current.settledOperationToken + 1,
            });
            return true;
        } catch {
            return false;
        }
    };
    const checkAccess = async (
        candidate?: Readonly<ItCommentIntent>,
    ): Promise<boolean> => {
        if (
            !positive(actorId) ||
            !positive(ticketId) ||
            currentScope.current !== scope
        )
            return false;
        const ephemeral = !candidate && !receiptIdentity.current;
        let identity: ItCommentIdentity;
        try {
            identity = candidate ??
                receiptIdentity.current ?? {
                    actorId,
                    ticketId,
                    isInternal,
                    requestUuid: newItCommentUuid(),
                };
        } catch {
            return false;
        }
        if (
            !identity ||
            active.current ||
            currentScope.current !== scope ||
            identity.actorId !== actorId ||
            identity.ticketId !== ticketId ||
            identity.isInternal !== isInternal
        )
            return false;
        const abort = new AbortController();
        active.current = abort;
        const operation = ++epoch.current;
        const previousStage = stateRef.current.stage;
        const same = () =>
            mounted.current &&
            operation === epoch.current &&
            currentScope.current === scope;
        authorizedVersion.current = null;
        update({ stage: 'checking_access', message: null });
        try {
            const nonce = newItCommentUuid();
            const response = await axios.post(
                '/it/drafts/validate-local-candidate',
                {
                    actor_user_id: identity.actorId,
                    purpose: identity.isInternal
                        ? 'internal_note'
                        : 'public_reply',
                    ticket_id: identity.ticketId,
                    memory_uuid: identity.requestUuid,
                    candidate_uuid: nonce,
                    fields: { body: '' },
                    step_index: 0,
                    base_ticket_version: identity.expectedVersion ?? null,
                    bound_scopes: [],
                },
                {
                    signal: abort.signal,
                    timeout: 30000,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            if (!same()) return false;
            const proof =
                response.status === 200
                    ? readItCommentAccessProof(response.data, identity, nonce)
                    : null;
            if (!proof)
                throw new Error('Current reply access could not be verified.');
            authorizedVersion.current = proof.currentVersion;
            update({
                stage: ephemeral
                    ? 'editing'
                    : previousStage === 'rejected'
                      ? 'rejected'
                      : 'unknown',
                accessConcealed: false,
                accessBlocker: proof.canSubmit
                    ? null
                    : (proof.blocker ?? {
                          code: 'submission_unavailable',
                          message:
                              'Review the current ticket before preparing another reply.',
                      }),
                message:
                    'Current access is verified. Keep the original reply identity while checking its outcome.',
            });
            return true;
        } catch (error) {
            if (!same()) return false;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 403 || status === 404) {
                intent.current = null;
                update({
                    stage: 'access',
                    accessConcealed: true,
                    errors: {},
                    message:
                        'This reply is no longer available to your current access.',
                });
                try {
                    callbacks.current.onAccessLost();
                } catch {
                    /* Keep the denied state. */
                }
            } else
                update({
                    stage:
                        status === 401 || status === 419
                            ? 'session'
                            : previousStage,
                    accessConcealed: true,
                    message:
                        'Current access was not confirmed. Sign in with the original account if needed, then retry the access check.',
                });
            return false;
        } finally {
            if (same()) active.current = null;
        }
    };
    const adoptAuthorizedIntent = async (
        candidate: Readonly<ItCommentIntent>,
    ): Promise<boolean> => {
        if (
            active.current ||
            intent.current ||
            currentScope.current !== scope ||
            !positive(actorId)
        )
            return false;
        let frozen: Readonly<ItCommentIntent>;
        try {
            frozen = freezeItCommentIntent(candidate);
            if (
                frozen.actorId !== actorId ||
                frozen.ticketId !== ticketId ||
                frozen.isInternal !== isInternal ||
                !readReferences(key).includes(frozen.requestUuid)
            )
                return false;
        } catch {
            return false;
        }
        if (
            !(await checkAccess(frozen)) ||
            currentScope.current !== scope ||
            !mounted.current
        )
            return false;
        try {
            const references = readReferences(key);
            if (!references.includes(frozen.requestUuid)) return false;
            intent.current = frozen;
            receiptIdentity.current = receiptOnly(frozen);
            uncertain.current = true;
            update({
                stage: 'unknown',
                requestUuid: frozen.requestUuid,
                references,
            });
            return true;
        } catch {
            return false;
        }
    };
    const prepareNext = (): boolean => {
        if (
            stateRef.current.stage !== 'committed' ||
            active.current ||
            currentScope.current !== scope
        )
            return false;
        try {
            const id = receiptIdentity.current?.requestUuid;
            const references = id
                ? updateReferences(key, id, false)
                : readReferences(key);
            intent.current = null;
            receiptIdentity.current = null;
            uncertain.current = false;
            idempotencyConflict.current = false;
            authorizedVersion.current = null;
            update({
                ...empty(),
                references,
                settledOperationToken: stateRef.current.settledOperationToken,
            });
            return true;
        } catch {
            return false;
        }
    };
    /** Parent-wide denial invalidates sibling transports without notifying recursively. */
    const denyCurrentAccess = () => {
        if (!mounted.current || currentScope.current !== scope) return;
        invalidateRequest();
        intent.current = null;
        if (receiptIdentity.current)
            receiptIdentity.current = receiptOnly(receiptIdentity.current);
        uncertain.current = true;
        authorizedVersion.current = null;
        update({
            stage: 'access',
            accessConcealed: true,
            errors: {},
            result: null,
            cancelled: null,
            message:
                'Current access must be verified before this audience can be used again.',
        });
    };
    const visible = renderScope === scope ? state : empty();
    return {
        ...visible,
        busy: [
            'sending',
            'recovering',
            'checking_access',
            'cancelling',
        ].includes(visible.stage),
        concealed:
            renderScope !== scope ||
            visible.accessConcealed ||
            ['session', 'access'].includes(visible.stage),
        frozenIntent: renderScope === scope ? intent.current : null,
        reviewedVersion: authorizedVersion.current,
        canRetryExact:
            visible.stage === 'unknown' &&
            !visible.accessConcealed &&
            !idempotencyConflict.current &&
            intent.current !== null,
        submit,
        recoverReference,
        stopWaiting,
        cancelReference,
        denyCurrentAccess,
        releaseKnownRejection,
        prepareNext,
        checkCurrentAccess: () => checkAccess(),
        adoptAuthorizedIntent,
        retryExact: () => {
            if (
                stateRef.current.stage === 'unknown' &&
                !stateRef.current.accessConcealed &&
                !idempotencyConflict.current
            )
                void execute(false);
        },
        recover: () => void execute(true),
    };
}

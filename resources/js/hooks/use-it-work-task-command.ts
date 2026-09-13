import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { newItCommentUuid } from './it-ticket-comment-contract';
import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';
import {
    freezeItWorkTaskIntent,
    itWorkTaskMutation,
    itWorkTaskReceiptPath,
    readItWorkTaskCancelled,
    readItWorkTaskCommitted,
    readItWorkTaskReview,
    taskFieldNames,
    type ItWorkTaskCancelled,
    type ItWorkTaskCommitted,
    type ItWorkTaskFields,
    type ItWorkTaskIdentity,
    type ItWorkTaskIntent,
    type ItWorkTaskOperation,
    type ItWorkTaskReview,
} from './it-work-task-command';

type Stage =
    | 'editing'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'reviewing'
    | 'reviewed'
    | 'rejected'
    | 'conflict'
    | 'unknown'
    | 'session'
    | 'access'
    | 'committed';
interface State {
    stage: Stage;
    message: string | null;
    errors: Record<string, string>;
    references: string[];
    requestUuid: string | null;
    result: ItWorkTaskCommitted | null;
    cancelled: ItWorkTaskCancelled | null;
    review: ItWorkTaskReview | null;
    concealed: boolean;
    settledOperationToken: number;
}
const initial = (): State => ({
    stage: 'editing',
    message: null,
    errors: {},
    references: [],
    requestUuid: null,
    result: null,
    cancelled: null,
    review: null,
    concealed: false,
    settledOperationToken: 0,
});
const positive = (value: number | undefined): value is number =>
    Number.isSafeInteger(value) && value! > 0;
function readReferences(key: string): string[] {
    const raw = sessionStorage.getItem(key);
    if (raw === null) return [];
    const value: unknown = JSON.parse(raw);
    if (
        !Array.isArray(value) ||
        value.length > 20 ||
        !value.every(
            (entry) => typeof entry === 'string' && IT_DRAFT_UUID.test(entry),
        )
    )
        throw new Error('Pending task references cannot be read.');
    return [...new Set(value as string[])];
}
function markReference(key: string, uuid: string, add: boolean): string[] {
    const previous = readReferences(key);
    const next = add
        ? [...new Set([...previous, uuid])]
        : previous.filter((item) => item !== uuid);
    if (next.length > 20)
        throw new Error('Resolve pending task commands first.');
    if (next.length) sessionStorage.setItem(key, JSON.stringify(next));
    else sessionStorage.removeItem(key);
    return next;
}

/** Opaque command references only; never infer an outcome or restore task fields here. */
export function pendingItWorkTaskCommands(actorId: number, ticketId: number) {
    const prefix = `it.pending-task-command.v1.${actorId}:${ticketId}:`;
    const references: ItWorkTaskIdentity[] = [];
    for (let index = 0; index < sessionStorage.length; index++) {
        const key = sessionStorage.key(index);
        if (!key?.startsWith(prefix)) continue;
        const [operation, rawTaskId, ...extra] = key
            .slice(prefix.length)
            .split(':');
        if (extra.length || !Object.hasOwn(taskFieldNames, operation)) continue;
        const taskId = rawTaskId === 'collection' ? null : Number(rawTaskId);
        if (
            operation === 'create' || operation === 'reorder'
                ? taskId !== null
                : !positive(taskId ?? undefined)
        )
            continue;
        for (const requestUuid of readReferences(key))
            references.push({
                actorId,
                ticketId,
                operation: operation as ItWorkTaskOperation,
                taskId,
                requestUuid,
            });
    }
    return references;
}
const receiptIdentity = (intent: ItWorkTaskIdentity): ItWorkTaskIdentity => ({
    actorId: intent.actorId,
    ticketId: intent.ticketId,
    operation: intent.operation,
    taskId: intent.taskId,
    requestUuid: intent.requestUuid,
    ...(intent.expectedVersion === undefined
        ? {}
        : { expectedVersion: intent.expectedVersion }),
});
function safeErrors(
    body: unknown,
    operation: ItWorkTaskOperation,
): Record<string, string> {
    if (!draftRecord(body) || !draftRecord(body.errors)) return {};
    return Object.fromEntries(
        Object.entries(body.errors).flatMap(([key, value]) => {
            if (
                ![
                    'form',
                    'expected_version',
                    ...taskFieldNames[operation],
                ].includes(key.split('.')[0])
            )
                return [];
            const text = Array.isArray(value)
                ? value.find((entry) => typeof entry === 'string')
                : value;
            return typeof text === 'string' ? [[key, text.slice(0, 1000)]] : [];
        }),
    );
}

/** Task transport; canonical records own outcomes and the shared RAM adapter owns private recovery. */
export function useItWorkTaskCommand({
    actorId,
    ticketId,
    operation,
    taskId,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number | undefined;
    ticketId: number;
    operation: ItWorkTaskOperation;
    taskId: number | null;
    onCommitted: (
        result: ItWorkTaskCommitted,
        original: Readonly<ItWorkTaskIntent> | null,
    ) => void;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}) {
    const scope = `${actorId ?? 'none'}:${ticketId}:${operation}:${taskId ?? 'collection'}`;
    const key = `it.pending-task-command.v1.${scope}`;
    const [state, setState] = useState<State>(initial);
    const [renderScope, setRenderScope] = useState(scope);
    const stateRef = useRef(state);
    const currentScope = useRef(scope);
    currentScope.current = scope;
    const callbacks = useRef({ onCommitted, onAccessLost, onSessionExpired });
    callbacks.current = { onCommitted, onAccessLost, onSessionExpired };
    const frozen = useRef<Readonly<ItWorkTaskIntent> | null>(null);
    const identity = useRef<ItWorkTaskIdentity | null>(null);
    const active = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const mounted = useRef(true);
    const uncertain = useRef(false);
    const reported = useRef(new Set<string>());
    const update = useCallback((patch: Partial<State>) => {
        if (!mounted.current) return;
        stateRef.current = { ...stateRef.current, ...patch };
        setState(stateRef.current);
    }, []);
    const invalidate = useCallback(() => {
        ++epoch.current;
        active.current?.abort();
        active.current = null;
    }, []);
    useEffect(() => {
        mounted.current = true;
        invalidate();
        frozen.current = null;
        identity.current = null;
        uncertain.current = false;
        stateRef.current = initial();
        setRenderScope(scope);
        try {
            update({ ...initial(), references: readReferences(key) });
        } catch {
            update({
                ...initial(),
                stage: 'rejected',
                message:
                    'Pending task references are unavailable. Restore browser session storage before sending; nothing has been sent.',
            });
        }
        return () => {
            mounted.current = false;
            invalidate();
            frozen.current = null;
            identity.current = null;
        };
    }, [scope, key, invalidate, update]);
    const deny = useCallback(() => {
        frozen.current = null;
        update({
            stage: 'access',
            concealed: true,
            review: null,
            errors: {},
            message:
                'Your access changed. Private task work has been removed from this form. Reload before continuing.',
        });
        try {
            callbacks.current.onAccessLost();
        } catch {
            /* The access boundary remains closed. */
        }
    }, [update]);
    const removeMarker = useCallback(
        (uuid: string) => {
            try {
                return markReference(key, uuid, false);
            } catch {
                return stateRef.current.references;
            }
        },
        [key],
    );

    const execute = useCallback(
        async (mode: 'send' | 'recover' | 'cancel') => {
            const original = identity.current;
            if (
                !original ||
                active.current ||
                currentScope.current !== scope ||
                (mode === 'send' && !frozen.current)
            )
                return;
            const controller = new AbortController();
            active.current = controller;
            const token = ++epoch.current;
            const valid = () =>
                mounted.current &&
                currentScope.current === scope &&
                epoch.current === token;
            const wasUncertain = uncertain.current;
            update({
                stage:
                    mode === 'send'
                        ? 'sending'
                        : mode === 'recover'
                          ? 'recovering'
                          : 'cancelling',
                message: null,
                errors: {},
                review: null,
            });
            const unknown = (message: string, conceal = false) => {
                uncertain.current = true;
                update({
                    stage: 'unknown',
                    message,
                    ...(conceal ? { concealed: true } : {}),
                });
            };
            try {
                const config = {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                };
                const context = {
                    actor_user_id: original.actorId,
                    ...(original.taskId === null
                        ? {}
                        : { task_id: original.taskId }),
                };
                const mutation =
                    mode === 'send'
                        ? itWorkTaskMutation(frozen.current!)
                        : null;
                const response =
                    mode === 'recover'
                        ? await axios.get(itWorkTaskReceiptPath(original), {
                              ...config,
                              params: context,
                          })
                        : mode === 'cancel'
                          ? await axios.post(
                                `${itWorkTaskReceiptPath(original)}/cancel`,
                                context,
                                config,
                            )
                          : await axios.request({ ...config, ...mutation });
                if (!valid()) return;
                const cancelled =
                    response.status === 200
                        ? readItWorkTaskCancelled(response.data, original)
                        : null;
                if (cancelled && mode !== 'send') {
                    frozen.current = null;
                    uncertain.current = false;
                    update({
                        stage: 'conflict',
                        cancelled,
                        references: removeMarker(original.requestUuid),
                        concealed: false,
                        errors: {},
                        review: null,
                        settledOperationToken:
                            stateRef.current.settledOperationToken + 1,
                        message:
                            'The earlier task command is cancelled. Review the current task before preparing another command; your proposal is retained.',
                    });
                    identity.current = null;
                    return;
                }
                const result = readItWorkTaskCommitted(response.data, original);
                const expectedStatus =
                    result &&
                    mode === 'send' &&
                    operation === 'create' &&
                    !result.replayed
                        ? 201
                        : 200;
                if (
                    !result ||
                    response.status !== expectedStatus ||
                    (mode === 'recover' && !result.replayed)
                ) {
                    unknown(
                        'The task result was not confirmed. Check the saved result before changing or resubmitting this command.',
                        true,
                    );
                    return;
                }
                const acknowledgedIntent =
                    frozen.current?.requestUuid === result.request_uuid
                        ? frozen.current
                        : null;
                uncertain.current = false;
                frozen.current = null;
                identity.current = null;
                update({
                    stage: 'committed',
                    result,
                    references: removeMarker(original.requestUuid),
                    concealed: false,
                    review: null,
                    message: null,
                    settledOperationToken:
                        stateRef.current.settledOperationToken + 1,
                });
                active.current = null;
                if (!reported.current.has(result.request_uuid)) {
                    reported.current.add(result.request_uuid);
                    try {
                        callbacks.current.onCommitted(
                            result,
                            acknowledgedIntent,
                        );
                    } catch {
                        update({
                            message:
                                'The task command is saved. Refresh the ticket to update its current work register.',
                        });
                    }
                }
            } catch (error) {
                if (!valid()) return;
                const response = axios.isAxiosError(error)
                    ? error.response
                    : undefined;
                if (response?.status === 401 || response?.status === 419) {
                    uncertain.current = true;
                    update({
                        stage: 'session',
                        concealed: true,
                        review: null,
                        message:
                            'Sign in with the same account, then check access and the original command result. Your task work remains concealed.',
                    });
                    callbacks.current.onSessionExpired?.();
                } else if (
                    response?.status === 403 ||
                    (response?.status === 404 && mode === 'send')
                ) {
                    deny();
                } else if (
                    response?.status === 409 &&
                    draftRecord(response.data) &&
                    response.data.code === 'idempotency_conflict'
                ) {
                    unknown(
                        'This reference identifies a different original command. Recover or explicitly cancel that command before preparing another.',
                    );
                } else if (
                    mode === 'send' &&
                    !wasUncertain &&
                    (response?.status === 422 ||
                        (response?.status === 409 &&
                            draftRecord(response.data) &&
                            response.data.code === 'stale_ticket'))
                ) {
                    frozen.current = null;
                    identity.current = null;
                    uncertain.current = false;
                    update({
                        stage:
                            response.status === 409 ? 'conflict' : 'rejected',
                        references: removeMarker(original.requestUuid),
                        errors: safeErrors(response.data, operation),
                        settledOperationToken:
                            stateRef.current.settledOperationToken + 1,
                        message:
                            response.status === 409
                                ? 'The ticket changed. Your task proposal was not applied. Review the current work before choosing a new version.'
                                : 'The task command was not applied. Correct the highlighted details; your proposal is retained.',
                    });
                } else {
                    unknown(
                        mode === 'recover' && response?.status === 404
                            ? 'No saved result is available yet. The original command may still finish. Keep its reference or explicitly cancel it.'
                            : 'The task outcome is unconfirmed. Check its saved result or retry the exact original command.',
                        mode === 'recover' && response?.status === 404,
                    );
                }
            } finally {
                if (valid()) active.current = null;
            }
        },
        [deny, operation, removeMarker, scope, update],
    );

    const submit = (
        fields: ItWorkTaskFields,
        expectedVersion: number,
    ): boolean => {
        if (
            !positive(actorId) ||
            active.current ||
            identity.current ||
            frozen.current ||
            currentScope.current !== scope ||
            !['editing', 'rejected'].includes(stateRef.current.stage) ||
            stateRef.current.concealed
        )
            return false;
        try {
            const references = readReferences(key);
            if (references.length) {
                update({
                    references,
                    message:
                        'Check or cancel pending task outcomes before starting another command.',
                });
                return false;
            }
            const proposal = freezeItWorkTaskIntent({
                actorId,
                ticketId,
                operation,
                taskId,
                requestUuid: newItCommentUuid(),
                expectedVersion,
                fields,
            });
            if (!proposal) {
                update({
                    stage: 'rejected',
                    message:
                        'Check the task details before sending. Nothing has been sent.',
                });
                return false;
            }
            const referencesAfter = markReference(
                key,
                proposal.requestUuid,
                true,
            );
            frozen.current = proposal;
            identity.current = receiptIdentity(proposal);
            uncertain.current = false;
            update({
                requestUuid: proposal.requestUuid,
                references: referencesAfter,
                result: null,
                cancelled: null,
                review: null,
            });
            void execute('send');
            return true;
        } catch {
            update({
                stage: 'rejected',
                message:
                    'The task could not be prepared safely. Allow session storage for its opaque recovery reference. Nothing was sent.',
            });
            return false;
        }
    };
    const selectReference = (uuid: string): boolean => {
        if (
            !positive(actorId) ||
            active.current ||
            currentScope.current !== scope ||
            !IT_DRAFT_UUID.test(uuid) ||
            (frozen.current && frozen.current.requestUuid !== uuid)
        )
            return false;
        try {
            if (!readReferences(key).includes(uuid)) return false;
        } catch {
            return false;
        }
        identity.current = frozen.current
            ? receiptIdentity(frozen.current)
            : { actorId, ticketId, operation, taskId, requestUuid: uuid };
        uncertain.current = true;
        update({ requestUuid: uuid });
        return true;
    };
    const recover = (uuid = identity.current?.requestUuid) => {
        if (uuid && selectReference(uuid)) void execute('recover');
    };
    const cancelCommand = (uuid = identity.current?.requestUuid) => {
        if (uuid && selectReference(uuid)) void execute('cancel');
    };
    const retry = () => {
        if (frozen.current && !stateRef.current.concealed && !active.current)
            void execute('send');
    };
    const cancelWait = () => {
        if (!active.current) return;
        invalidate();
        uncertain.current = identity.current !== null || uncertain.current;
        update({
            stage: uncertain.current ? 'unknown' : 'conflict',
            review: null,
            message:
                'Stopped waiting. A submitted command may still finish. Your proposal and its original reference are retained.',
        });
    };
    const reviewCurrent = async () => {
        if (
            !positive(actorId) ||
            active.current ||
            currentScope.current !== scope ||
            stateRef.current.stage === 'access'
        )
            return;
        const controller = new AbortController();
        active.current = controller;
        const token = ++epoch.current;
        const valid = () =>
            mounted.current &&
            currentScope.current === scope &&
            epoch.current === token;
        update({ stage: 'reviewing', review: null, message: null });
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
                response.status === 200 &&
                draftRecord(response.data) &&
                ((typeof response.data.viewer_user_id === 'number' &&
                    response.data.viewer_user_id !== actorId) ||
                    (draftRecord(response.data.can) &&
                        response.data.can.manage === false))
            ) {
                deny();
                return;
            }
            const review =
                response.status === 200
                    ? readItWorkTaskReview(response.data, {
                          actorId,
                          ticketId,
                          taskId,
                      })
                    : null;
            if (!review) {
                update({
                    stage: 'conflict',
                    concealed: true,
                    message:
                        'Current authorized task details could not be confirmed. Your proposal is retained; retry the current review.',
                });
                return;
            }
            update({
                stage: 'reviewed',
                review,
                concealed: false,
                message:
                    uncertain.current || identity.current
                        ? 'Current details are available. The original command remains unresolved; check or cancel its saved outcome before changing its version.'
                        : review.canManage
                          ? 'Compare the current work with your retained proposal. Choosing this version will not submit a command.'
                          : 'This ticket no longer accepts task changes. Your proposal is retained for review.',
            });
        } catch (error) {
            if (!valid()) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 403 || status === 404) deny();
            else {
                update({
                    stage:
                        status === 401 || status === 419
                            ? 'session'
                            : 'conflict',
                    ...(status === 401 || status === 419
                        ? { concealed: true }
                        : {}),
                    review: null,
                    message:
                        status === 401 || status === 419
                            ? 'Sign in with the same account, then retry the current task review.'
                            : 'Current task details could not be loaded. Your proposal and pending command are retained.',
                });
                if (status === 401 || status === 419)
                    callbacks.current.onSessionExpired?.();
            }
        } finally {
            if (valid()) active.current = null;
        }
    };
    const adoptReview = (): ItWorkTaskReview | null => {
        const review = stateRef.current.review;
        if (
            !review ||
            !review.canManage ||
            active.current ||
            uncertain.current ||
            identity.current ||
            stateRef.current.concealed
        )
            return null;
        try {
            if (readReferences(key).length) return null;
        } catch {
            return null;
        }
        update({
            stage: 'editing',
            review: null,
            errors: {},
            cancelled: null,
            message:
                'The reviewed version is selected. Your proposal has not been applied; review it and submit explicitly.',
            settledOperationToken: stateRef.current.settledOperationToken + 1,
        });
        return review;
    };
    // The shared RAM owner must supply this only after its fresh, nonce-bound
    // authorization of the original nested task and all selected bindings.
    const canRestoreIntent = (candidate: ItWorkTaskIntent): boolean => {
        if (
            !positive(actorId) ||
            active.current ||
            (identity.current !== null &&
                identity.current.requestUuid !== candidate.requestUuid) ||
            candidate.actorId !== actorId ||
            candidate.ticketId !== ticketId ||
            candidate.operation !== operation ||
            candidate.taskId !== taskId ||
            currentScope.current !== scope
        )
            return false;
        const original = freezeItWorkTaskIntent(candidate);
        if (!original) return false;
        return (
            frozen.current === null ||
            JSON.stringify(frozen.current) === JSON.stringify(original)
        );
    };
    const restoreAuthorizedIntent = (candidate: ItWorkTaskIntent): boolean => {
        if (!canRestoreIntent(candidate)) return false;
        const original = freezeItWorkTaskIntent(candidate)!;
        if (frozen.current) return true;
        try {
            const references = markReference(key, original.requestUuid, true);
            frozen.current = original;
            identity.current = receiptIdentity(original);
            uncertain.current = true;
            update({
                stage: 'unknown',
                requestUuid: original.requestUuid,
                references,
                concealed: false,
                message:
                    'The original task command is retained. Check its result or retry it exactly before editing this proposal.',
            });
            return true;
        } catch {
            return false;
        }
    };
    const visible =
        renderScope === scope ? state : { ...initial(), concealed: true };
    return {
        ...visible,
        busy: ['sending', 'recovering', 'cancelling', 'reviewing'].includes(
            visible.stage,
        ),
        canEdit:
            !visible.concealed &&
            ['editing', 'rejected'].includes(visible.stage) &&
            !identity.current &&
            visible.references.length === 0,
        outcomeUnknown:
            uncertain.current || (identity.current !== null && !visible.result),
        pendingIntent: frozen.current,
        submit,
        recover,
        retry,
        cancelCommand,
        cancelWait,
        reviewCurrent,
        adoptReview,
        restoreAuthorizedIntent,
        canRestoreIntent,
        denyCurrentAccess: deny,
        clearFieldError: (field: string) =>
            update({
                errors: Object.fromEntries(
                    Object.entries(stateRef.current.errors).filter(
                        ([key]) =>
                            key !== field && !key.startsWith(`${field}.`),
                    ),
                ),
            }),
    };
}

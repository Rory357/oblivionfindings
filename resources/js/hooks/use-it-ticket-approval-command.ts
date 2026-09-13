import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    freezeItApprovalIntent,
    itApprovalMutation,
    itApprovalReceiptParameters,
    itApprovalReceiptPath,
    readItApprovalCancelled,
    readItApprovalCommitted,
    type ItApprovalCancelled,
    type ItApprovalCommitted,
    type ItApprovalIdentity,
    type ItApprovalIntent,
    type ItApprovalOperation,
} from './it-ticket-approval-contract';
import { newItCommentUuid } from './it-ticket-comment-contract';
import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

type Stage =
    | 'editing'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'rejected'
    | 'conflict'
    | 'unknown'
    | 'session'
    | 'access'
    | 'committed'
    | 'cancelled';
interface State {
    stage: Stage;
    message: string | null;
    errors: Record<string, string>;
    references: string[];
    requestUuid: string | null;
    result: ItApprovalCommitted | null;
    cancelled: ItApprovalCancelled | null;
    concealed: boolean;
    settledOperationToken: number;
}
export interface ItApprovalCurrentAccess {
    actorId: number;
    ticketId: number;
    operation: ItApprovalOperation;
    approvalId: number | null;
    currentTicketVersion: number;
}
const initial = (): State => ({
    stage: 'editing',
    message: null,
    errors: {},
    references: [],
    requestUuid: null,
    result: null,
    cancelled: null,
    concealed: false,
    settledOperationToken: 0,
});
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const prefix = 'it.pending-approval-command.v1.';
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
        throw new Error('Pending approval references are unavailable.');
    return [...new Set(value as string[])];
}
function markReference(key: string, uuid: string, add: boolean): string[] {
    const previous = readReferences(key);
    const next = add
        ? [...new Set([...previous, uuid])]
        : previous.filter((entry) => entry !== uuid);
    if (next.length > 20)
        throw new Error('Resolve pending approval commands first.');
    if (next.length) sessionStorage.setItem(key, JSON.stringify(next));
    else sessionStorage.removeItem(key);
    return next;
}
/** Opaque identities only. This never recovers approval reasons or infers a command result. */
export function pendingItApprovalCommands(
    actorId: number,
    ticketId: number,
): ItApprovalIdentity[] {
    if (!positive(actorId) || !positive(ticketId)) return [];
    const scopePrefix = `${prefix}${actorId}:${ticketId}:`;
    const references: ItApprovalIdentity[] = [];
    for (let index = 0; index < sessionStorage.length; index++) {
        const key = sessionStorage.key(index);
        if (!key?.startsWith(scopePrefix)) continue;
        const [operation, rawId, ...extra] = key
            .slice(scopePrefix.length)
            .split(':');
        if (
            extra.length ||
            !['request', 'decide', 'withdraw'].includes(operation)
        )
            continue;
        const approvalId = rawId === 'collection' ? null : Number(rawId);
        if (
            operation === 'request'
                ? approvalId !== null
                : !positive(approvalId)
        )
            continue;
        for (const requestUuid of readReferences(key))
            references.push({
                actorId,
                ticketId,
                operation: operation as ItApprovalOperation,
                approvalId,
                requestUuid,
            });
    }
    return references;
}
const identityOf = (intent: ItApprovalIdentity): ItApprovalIdentity => ({
    actorId: intent.actorId,
    ticketId: intent.ticketId,
    operation: intent.operation,
    approvalId: intent.approvalId,
    requestUuid: intent.requestUuid,
    ...(intent.expectedVersion === undefined
        ? {}
        : { expectedVersion: intent.expectedVersion }),
});
const referenceKey = (identity: ItApprovalIdentity) =>
    `${identity.actorId}:${identity.ticketId}:${identity.operation}:${identity.approvalId ?? 'collection'}:${identity.requestUuid}`;
function safeErrors(body: unknown): Record<string, string> {
    if (!draftRecord(body) || !draftRecord(body.errors)) return {};
    return Object.fromEntries(
        Object.entries(body.errors).flatMap(([key, value]) => {
            if (
                ![
                    'form',
                    'reason',
                    'decision',
                    'expected_version',
                    'primary_approver_user_id',
                    'cover_approver_user_id',
                    'expires_at',
                    'remind_at',
                ].includes(key)
            )
                return [];
            const message = Array.isArray(value)
                ? value.find((entry) => typeof entry === 'string')
                : value;
            return typeof message === 'string'
                ? [[key, message.slice(0, 1000)]]
                : [];
        }),
    );
}

/** Canonical receipts own outcomes; the host owns authorized private review and RAM retention. */
export function useItTicketApprovalCommand({
    actorId,
    ticketId,
    operation,
    approvalId,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number | undefined;
    ticketId: number;
    operation: ItApprovalOperation;
    approvalId: number | null;
    onCommitted: (
        result: ItApprovalCommitted,
        original: Readonly<ItApprovalIntent> | null,
    ) => void;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}) {
    const scope = `${actorId ?? 'none'}:${ticketId}:${operation}:${approvalId ?? 'collection'}`;
    const key = `${prefix}${scope}`;
    const [state, setState] = useState<State>(initial);
    const [renderScope, setRenderScope] = useState(scope);
    const stateRef = useRef(state);
    const liveScope = useRef(scope);
    liveScope.current = scope;
    const callbacks = useRef({ onCommitted, onAccessLost, onSessionExpired });
    callbacks.current = { onCommitted, onAccessLost, onSessionExpired };
    const frozen = useRef<Readonly<ItApprovalIntent> | null>(null);
    const identity = useRef<ItApprovalIdentity | null>(null);
    const active = useRef<AbortController | null>(null);
    const mounted = useRef(true);
    const epoch = useRef(0);
    const uncertain = useRef(false);
    const reviewedVersion = useRef<number | null>(null);
    const reported = useRef(new Set<string>());
    const settled = useRef(new Set<string>());
    const mismatched = useRef(new Set<string>());
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
        reviewedVersion.current = null;
        setRenderScope(scope);
        try {
            update({ ...initial(), references: readReferences(key) });
        } catch {
            update({
                ...initial(),
                stage: 'rejected',
                message:
                    'Pending approval references are unavailable. Restore browser session storage before sending; nothing has been sent.',
            });
        }
        return () => {
            mounted.current = false;
            invalidate();
            frozen.current = null;
            identity.current = null;
        };
    }, [scope, key, invalidate, update]);
    const denyCurrentAccess = useCallback(() => {
        invalidate();
        frozen.current = null;
        reviewedVersion.current = null;
        update({
            stage: 'access',
            concealed: true,
            result: null,
            cancelled: null,
            errors: {},
            message:
                'Your access changed. Private approval work has been removed. Check current access before continuing.',
        });
    }, [invalidate, update]);
    const deny = useCallback(() => {
        denyCurrentAccess();
        try {
            callbacks.current.onAccessLost();
        } catch {
            /* The boundary remains closed. */
        }
    }, [denyCurrentAccess]);
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
        async (mode: 'send' | 'recover' | 'cancel'): Promise<boolean> => {
            const original = identity.current;
            if (
                !original ||
                !mounted.current ||
                active.current ||
                liveScope.current !== scope ||
                (mode === 'send' &&
                    (!frozen.current || stateRef.current.concealed))
            )
                return false;
            const controller = new AbortController();
            active.current = controller;
            const token = ++epoch.current;
            const valid = () =>
                mounted.current &&
                liveScope.current === scope &&
                epoch.current === token;
            const wasUncertain = uncertain.current;
            reviewedVersion.current = null;
            update({
                stage:
                    mode === 'send'
                        ? 'sending'
                        : mode === 'recover'
                          ? 'recovering'
                          : 'cancelling',
                errors: {},
                message: null,
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
                const parameters = itApprovalReceiptParameters(original);
                const mutation =
                    mode === 'send'
                        ? itApprovalMutation(frozen.current!)
                        : null;
                const response =
                    mode === 'recover'
                        ? await axios.get(itApprovalReceiptPath(original), {
                              ...config,
                              params: parameters,
                          })
                        : mode === 'cancel'
                          ? await axios.post(
                                `${itApprovalReceiptPath(original)}/cancel`,
                                parameters,
                                config,
                            )
                          : await axios.post(
                                mutation!.url,
                                mutation!.data,
                                config,
                            );
                if (!valid()) return false;
                if (
                    draftRecord(response.data) &&
                    draftRecord(response.data.data) &&
                    positive(response.data.data.viewer_user_id) &&
                    response.data.data.viewer_user_id !== original.actorId
                ) {
                    deny();
                    return false;
                }
                const cancelled =
                    response.status === 200
                        ? readItApprovalCancelled(response.data, original)
                        : null;
                if (cancelled && (mode !== 'recover' || cancelled.replayed)) {
                    settled.current.add(referenceKey(original));
                    uncertain.current = false;
                    frozen.current = null;
                    identity.current = null;
                    update({
                        stage: 'cancelled',
                        cancelled,
                        references: removeMarker(original.requestUuid),
                        errors: {},
                        settledOperationToken:
                            stateRef.current.settledOperationToken + 1,
                        message:
                            'The original approval command is cancelled. Review current details before preparing another command.',
                    });
                    return true;
                }
                const result = readItApprovalCommitted(
                    response.data,
                    frozen.current ?? original,
                );
                const expectedStatus =
                    mode === 'send' &&
                    operation === 'request' &&
                    result &&
                    !result.replayed
                        ? 201
                        : 200;
                if (
                    !result ||
                    response.status !== expectedStatus ||
                    (mode === 'recover' && !result.replayed)
                ) {
                    unknown(
                        'The approval result was not confirmed. Check the saved result before changing or resubmitting this command.',
                        true,
                    );
                    return false;
                }
                const acknowledged = frozen.current;
                settled.current.add(referenceKey(original));
                uncertain.current = false;
                frozen.current = null;
                identity.current = null;
                update({
                    stage: 'committed',
                    result,
                    cancelled: null,
                    references: removeMarker(original.requestUuid),
                    errors: {},
                    message: null,
                    settledOperationToken:
                        stateRef.current.settledOperationToken + 1,
                });
                active.current = null;
                if (!reported.current.has(referenceKey(original))) {
                    reported.current.add(referenceKey(original));
                    try {
                        callbacks.current.onCommitted(result, acknowledged);
                    } catch {
                        if (valid())
                            update({
                                message:
                                    'The approval command is saved. Refresh the ticket to review its current approval state.',
                            });
                    }
                }
                return true;
            } catch (error) {
                if (!valid()) return false;
                const response = axios.isAxiosError(error)
                    ? error.response
                    : undefined;
                if (response?.status === 401 || response?.status === 419) {
                    uncertain.current = true;
                    update({
                        stage: 'session',
                        concealed: true,
                        errors: {},
                        message:
                            'Sign in with the same account, then check current access and the original approval result. Your proposal remains concealed.',
                    });
                    try {
                        callbacks.current.onSessionExpired?.();
                    } catch {
                        /* Session concealment remains in force. */
                    }
                } else if (
                    response?.status === 403 ||
                    (response?.status === 404 &&
                        (mode === 'send' ||
                            (draftRecord(response.data) &&
                                response.data.code === 'access_unavailable')))
                ) {
                    deny();
                } else if (
                    response?.status === 409 &&
                    draftRecord(response.data) &&
                    response.data.code === 'idempotency_conflict'
                ) {
                    // The receipt belongs to another payload. Keep its opaque
                    // identity, but never acknowledge or retry this rejected intent
                    // as if it were the original command. The host retains its form.
                    mismatched.current.add(referenceKey(original));
                    frozen.current = null;
                    identity.current = {
                        actorId: original.actorId,
                        ticketId: original.ticketId,
                        operation: original.operation,
                        approvalId: original.approvalId,
                        requestUuid: original.requestUuid,
                    };
                    unknown(
                        'This reference belongs to a different original command. Recover or explicitly cancel that command before preparing another.',
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
                        errors: safeErrors(response.data),
                        settledOperationToken:
                            stateRef.current.settledOperationToken + 1,
                        message:
                            response.status === 409
                                ? 'The ticket changed. Your proposal was not applied. Review current details before choosing a new version.'
                                : 'The approval command was not applied. Correct the highlighted details; your proposal is retained.',
                    });
                } else {
                    unknown(
                        response?.status === 404
                            ? 'No saved result is available yet. The original command may still finish. Keep its reference or explicitly cancel it.'
                            : 'The approval outcome is unconfirmed. Check its saved result or retry the exact original command.',
                        mode !== 'send' && response?.status === 404,
                    );
                }
                return false;
            } finally {
                if (valid()) active.current = null;
            }
        },
        [deny, operation, removeMarker, scope, update],
    );

    const submit = (
        fields: ItApprovalIntent['fields'],
        expectedVersion: number,
    ): boolean => {
        if (
            !positive(actorId) ||
            !mounted.current ||
            active.current ||
            identity.current ||
            frozen.current ||
            liveScope.current !== scope ||
            stateRef.current.concealed ||
            !['editing', 'rejected'].includes(stateRef.current.stage)
        )
            return false;
        try {
            const references = readReferences(key);
            if (references.length) {
                update({
                    references,
                    message:
                        'Check or cancel pending approval outcomes before starting another command.',
                });
                return false;
            }
            const proposal = freezeItApprovalIntent(
                {
                    actorId,
                    ticketId,
                    operation,
                    approvalId,
                    requestUuid: newItCommentUuid(),
                    expectedVersion,
                },
                fields,
            );
            if (!proposal) {
                update({
                    stage: 'rejected',
                    message:
                        'Check the approval details before sending. Nothing has been sent.',
                });
                return false;
            }
            const referencesAfter = markReference(
                key,
                proposal.requestUuid,
                true,
            );
            frozen.current = proposal;
            identity.current = identityOf(proposal);
            uncertain.current = false;
            update({
                requestUuid: proposal.requestUuid,
                references: referencesAfter,
                result: null,
                cancelled: null,
            });
            void execute('send');
            return true;
        } catch {
            update({
                stage: 'rejected',
                message:
                    'The approval could not be prepared safely. Allow session storage for its opaque recovery reference. Nothing was sent.',
            });
            return false;
        }
    };
    const selectReference = (uuid: string): boolean => {
        if (
            !positive(actorId) ||
            !mounted.current ||
            active.current ||
            liveScope.current !== scope ||
            !IT_DRAFT_UUID.test(uuid) ||
            (identity.current && identity.current.requestUuid !== uuid)
        )
            return false;
        try {
            if (!readReferences(key).includes(uuid)) return false;
        } catch {
            return false;
        }
        identity.current = frozen.current
            ? identityOf(frozen.current)
            : { actorId, ticketId, operation, approvalId, requestUuid: uuid };
        uncertain.current = true;
        update({ requestUuid: uuid });
        return true;
    };
    const recover = async (uuid = identity.current?.requestUuid) =>
        uuid && selectReference(uuid) ? execute('recover') : false;
    const cancelCommand = async (uuid = identity.current?.requestUuid) =>
        uuid && selectReference(uuid) ? execute('cancel') : false;
    const retry = async () =>
        frozen.current &&
        !stateRef.current.concealed &&
        ['unknown', 'conflict'].includes(stateRef.current.stage)
            ? execute('send')
            : false;
    const stopWaiting = () => {
        if (!active.current || liveScope.current !== scope) return;
        invalidate();
        uncertain.current = true;
        update({
            stage: 'unknown',
            message:
                'Stopped waiting. The original approval command may still finish. Its reference and exact proposal are retained.',
        });
    };
    // Only the host's fresh canonical, actor-bound review may supply this proof.
    // It never replaces a frozen command's original expected version.
    const confirmCurrentAccess = (proof: ItApprovalCurrentAccess): boolean => {
        if (
            !mounted.current ||
            active.current ||
            liveScope.current !== scope ||
            proof.actorId !== actorId ||
            proof.ticketId !== ticketId ||
            proof.operation !== operation ||
            proof.approvalId !== approvalId ||
            !positive(proof.currentTicketVersion) ||
            (stateRef.current.result &&
                proof.currentTicketVersion <
                    stateRef.current.result.lock_version)
        )
            return false;
        reviewedVersion.current = proof.currentTicketVersion;
        update({
            concealed: false,
            ...(['session', 'access'].includes(stateRef.current.stage)
                ? {
                      stage: identity.current
                          ? ('unknown' as const)
                          : ('conflict' as const),
                  }
                : {}),
        });
        return true;
    };
    const reset = (version?: number): boolean => {
        if (
            !mounted.current ||
            active.current ||
            identity.current ||
            uncertain.current ||
            liveScope.current !== scope ||
            stateRef.current.concealed ||
            ![
                'committed',
                'cancelled',
                'conflict',
                'rejected',
                'editing',
            ].includes(stateRef.current.stage)
        )
            return false;
        if (
            ['conflict', 'cancelled'].includes(stateRef.current.stage) &&
            (!positive(version) || version !== reviewedVersion.current)
        )
            return false;
        try {
            if (readReferences(key).length) return false;
        } catch {
            return false;
        }
        frozen.current = null;
        update({
            ...initial(),
            settledOperationToken: stateRef.current.settledOperationToken + 1,
        });
        return true;
    };
    const canRestoreIntent = (candidate: ItApprovalIntent): boolean => {
        if (
            !mounted.current ||
            active.current ||
            liveScope.current !== scope ||
            candidate.actorId !== actorId ||
            candidate.ticketId !== ticketId ||
            candidate.operation !== operation ||
            candidate.approvalId !== approvalId ||
            settled.current.has(referenceKey(candidate)) ||
            mismatched.current.has(referenceKey(candidate)) ||
            (identity.current &&
                identity.current.requestUuid !== candidate.requestUuid)
        )
            return false;
        const checked = freezeItApprovalIntent(candidate, candidate.fields);
        return (
            checked !== null &&
            (frozen.current === null ||
                JSON.stringify(frozen.current) === JSON.stringify(checked))
        );
    };
    /** Call only after the host freshly authorizes this exact private RAM candidate. */
    const restoreAuthorizedIntent = (candidate: ItApprovalIntent): boolean => {
        if (!canRestoreIntent(candidate)) return false;
        const checked = freezeItApprovalIntent(candidate, candidate.fields)!;
        try {
            const references = markReference(key, checked.requestUuid, true);
            frozen.current = checked;
            identity.current = identityOf(checked);
            uncertain.current = true;
            update({
                stage: 'unknown',
                concealed: false,
                references,
                requestUuid: checked.requestUuid,
                result: null,
                cancelled: null,
                errors: {},
                message:
                    'The original approval command is retained. Check its saved result or retry it exactly before editing.',
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
        busy: ['sending', 'recovering', 'cancelling'].includes(visible.stage),
        canEdit:
            !visible.concealed &&
            ['editing', 'rejected'].includes(visible.stage) &&
            !identity.current &&
            visible.references.length === 0,
        outcomeUnknown:
            renderScope === scope &&
            (uncertain.current || identity.current !== null),
        pendingIntent:
            renderScope === scope && !visible.concealed ? frozen.current : null,
        submit,
        retry,
        recover,
        cancelCommand,
        stopWaiting,
        reset,
        confirmCurrentAccess,
        denyCurrentAccess,
        canRestoreIntent,
        restoreAuthorizedIntent,
        clearFieldError: (field: string) =>
            update({
                errors: Object.fromEntries(
                    Object.entries(stateRef.current.errors).filter(
                        ([name]) => name !== field,
                    ),
                ),
            }),
    };
}

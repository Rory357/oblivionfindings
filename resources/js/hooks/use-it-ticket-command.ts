import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

export type ItTicketCommandState =
    | 'idle'
    | 'submitting'
    | 'recovering'
    | 'validation_error'
    | 'session_expired'
    | 'access_denied'
    | 'outcome_unknown'
    | 'conflict'
    | 'committed'
    | 'unavailable';

export interface ItTicketCommandResult {
    id: number;
    reference: string;
    url: string;
    request_uuid: string;
    replayed: boolean;
}

interface CommandSnapshot {
    state: ItTicketCommandState;
    message: string | null;
    fieldErrors: Record<string, string>;
    result: ItTicketCommandResult | null;
    requestId: string | null;
    restoredFromReference: boolean;
}

function secureRequestId(): string {
    const cryptoApi = globalThis.crypto;
    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }
    if (typeof cryptoApi?.getRandomValues !== 'function') {
        throw new Error('Secure UUID generation is not available.');
    }
    // Same cryptographic fallback as the established incident-draft hook.
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function pendingReferenceKey(actorId?: number): string | null {
    return Number.isSafeInteger(actorId) && actorId! > 0
        ? `it.pending-ticket-command.v1.actor.${actorId}`
        : null;
}

function initialSnapshot(
    storageKey: string | null = null,
    draftRequestId?: string,
): CommandSnapshot {
    try {
        const requestId = storageKey
            ? sessionStorage.getItem(storageKey)
            : null;
        if (
            requestId &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
                requestId,
            )
        ) {
            return {
                state: 'outcome_unknown',
                message:
                    'A previous request has not been confirmed. Check its saved result before starting another. Only its request reference was kept; details and files are no longer available here.',
                fieldErrors: {},
                result: null,
                requestId,
                restoredFromReference: true,
            };
        }
        if (requestId && storageKey) sessionStorage.removeItem(storageKey);
    } catch {
        // Browser storage may be disabled; ordinary in-memory intake still works.
    }
    try {
        return {
            state: 'idle',
            message: null,
            fieldErrors: {},
            result: null,
            requestId:
                draftRequestId &&
                /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
                    draftRequestId,
                )
                    ? draftRequestId
                    : secureRequestId(),
            restoredFromReference: false,
        };
    } catch {
        return {
            state: 'unavailable',
            message:
                'A secure request identity could not be created. Refresh this secure page before submitting.',
            fieldErrors: {},
            result: null,
            requestId: null,
            restoredFromReference: false,
        };
    }
}

function record(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function committedResult(
    value: unknown,
    requestId: string,
    actorId?: number,
): ItTicketCommandResult | null {
    if (!record(value) || value.status !== 'committed' || !record(value.data))
        return null;
    const data = value.data;
    if (
        typeof data.id !== 'number' ||
        !Number.isSafeInteger(data.id) ||
        data.id < 1 ||
        typeof data.reference !== 'string' ||
        !/^IT-\d{6,}$/.test(data.reference) ||
        data.url !== `/it/tickets/${data.id}` ||
        data.request_uuid !== requestId ||
        (actorId !== undefined && data.viewer_user_id !== actorId) ||
        typeof data.replayed !== 'boolean'
    )
        return null;
    return {
        id: data.id,
        reference: data.reference,
        url: data.url,
        request_uuid: requestId,
        replayed: data.replayed,
    };
}

function copyPayload(
    source: FormData,
    requestId: string,
    actorId?: number,
): FormData {
    const copy = new FormData();
    source.forEach((value, key) => {
        if (key !== 'request_uuid' && key !== 'actor_user_id')
            copy.append(key, value);
    });
    copy.set('request_uuid', requestId);
    if (actorId !== undefined) copy.set('actor_user_id', String(actorId));
    return copy;
}

function fieldErrorsFrom(value: unknown): Record<string, string> {
    if (!record(value) || !record(value.errors)) return {};
    return Object.fromEntries(
        Object.entries(value.errors).flatMap(([field, messages]) => {
            if (!/^[a-zA-Z0-9_.[\]-]{1,100}$/.test(field)) return [];
            const first = Array.isArray(messages)
                ? messages.find(
                      (message) =>
                          typeof message === 'string' && message.trim(),
                  )
                : messages;
            return typeof first === 'string' && first.trim()
                ? [[field, first.slice(0, 1000)]]
                : [];
        }),
    );
}

/**
 * One in-memory browser command. An uncertain result keeps its exact multipart
 * payload and UUID until recovery or an explicitly acknowledged new/discard.
 * The original UUID is retained before sending in actor-scoped session storage;
 * no draft, record data or file is stored there. Aborting HTTP never cancels the server.
 */
export function useItTicketCommand({
    timeoutMs = 30_000,
    actorId,
    draftRequestId,
}: { timeoutMs?: number; actorId?: number; draftRequestId?: string } = {}) {
    const storageKey = pendingReferenceKey(actorId);
    const scopeRef = useRef(storageKey);
    const [snapshot, setSnapshot] = useState<CommandSnapshot>(() =>
        initialSnapshot(storageKey, draftRequestId),
    );
    const snapshotRef = useRef(snapshot);
    const mounted = useRef(true);
    const frozenPayload = useRef<FormData | null>(null);
    const active = useRef<AbortController | null>(null);
    const generation = useRef(0);
    const uncertain = useRef(false);
    const settledOperationToken = useRef(0);

    const update = useCallback((change: Partial<CommandSnapshot>) => {
        snapshotRef.current = { ...snapshotRef.current, ...change };
        if (mounted.current) setSnapshot(snapshotRef.current);
    }, []);

    const abortOperation = useCallback(() => {
        generation.current++;
        active.current?.abort();
        active.current = null;
    }, []);

    const clearPendingReference = useCallback(() => {
        try {
            if (
                storageKey &&
                sessionStorage.getItem(storageKey) ===
                    snapshotRef.current.requestId
            ) {
                sessionStorage.removeItem(storageKey);
            }
        } catch {
            // An inaccessible marker never authorizes a receipt or stores its payload.
        }
    }, [storageKey]);

    useEffect(() => {
        if (scopeRef.current === storageKey) return;
        abortOperation();
        frozenPayload.current = null;
        uncertain.current = false;
        scopeRef.current = storageKey;
        update(initialSnapshot(storageKey, draftRequestId));
    }, [storageKey, abortOperation, update, draftRequestId]);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            abortOperation();
            frozenPayload.current = null;
        };
    }, [abortOperation]);

    const execute = useCallback(
        async (
            operation: 'submit' | 'recover',
        ): Promise<ItTicketCommandResult | null> => {
            const requestId = snapshotRef.current.requestId;
            if (
                !mounted.current ||
                scopeRef.current !== storageKey ||
                active.current ||
                !requestId ||
                snapshotRef.current.state === 'access_denied'
            )
                return null;
            if (operation === 'submit' && !frozenPayload.current) return null;

            const controller = new AbortController();
            const operationGeneration = ++generation.current;
            active.current = controller;
            update({
                state: operation === 'submit' ? 'submitting' : 'recovering',
                message: null,
                fieldErrors: {},
            });
            const options = {
                signal: controller.signal,
                timeout: timeoutMs,
                withCredentials: true,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };
            const isCurrent = () =>
                mounted.current && generation.current === operationGeneration;
            try {
                if (operation === 'submit' && storageKey) {
                    try {
                        // Native history traversal may unmount this form while
                        // the server is still committing. Retain only its opaque
                        // identity before sending; never browser-store the body.
                        sessionStorage.setItem(storageKey, requestId);
                    } catch {
                        // Storage availability does not establish a server result.
                    }
                }
                const response =
                    operation === 'submit'
                        ? await axios.post<unknown>(
                              '/it/tickets',
                              copyPayload(
                                  frozenPayload.current!,
                                  requestId,
                                  actorId,
                              ),
                              options,
                          )
                        : await axios.get<unknown>(
                              `/it/ticket-commands/${requestId}`,
                              {
                                  ...options,
                                  ...(actorId !== undefined
                                      ? { params: { actor_user_id: actorId } }
                                      : {}),
                              },
                          );
                if (!isCurrent()) return null;
                const result = [200, 201].includes(response.status)
                    ? committedResult(response.data, requestId, actorId)
                    : null;
                if (!result) {
                    uncertain.current = true;
                    update({
                        state: 'outcome_unknown',
                        result: null,
                        message:
                            'The response did not confirm a saved request. Check the original request before retrying.',
                    });
                    return null;
                }
                uncertain.current = false;
                settledOperationToken.current++;
                frozenPayload.current = null;
                clearPendingReference();
                update({
                    state: 'committed',
                    result,
                    message: null,
                    fieldErrors: {},
                });
                return result;
            } catch (error: unknown) {
                if (!isCurrent()) return null;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                const errorData = axios.isAxiosError(error)
                    ? error.response?.data
                    : null;
                if (
                    status === 403 ||
                    (status === 404 &&
                        record(errorData) &&
                        errorData.code === 'access_unavailable')
                ) {
                    frozenPayload.current = null;
                    clearPendingReference();
                    update({
                        state: 'access_denied',
                        result: null,
                        fieldErrors: {},
                        message:
                            'Your access has changed. The submitted browser copy has been cleared. Restore your access before checking this request.',
                    });
                } else if (status === 401 || status === 419) {
                    uncertain.current = true;
                    update({
                        state: 'session_expired',
                        result: null,
                        message:
                            'Your session needs to be restored. Sign in again, then check the original request.',
                    });
                } else if (
                    status === 422 &&
                    operation === 'submit' &&
                    !uncertain.current
                ) {
                    // A definitive first-attempt validation rejection reserves no
                    // command. A later 422 cannot disprove an earlier lost commit.
                    frozenPayload.current = null;
                    clearPendingReference();
                    const fieldErrors = fieldErrorsFrom(
                        // Only this definitive first rejection releases a pending RAM intent.
                        axios.isAxiosError(error) ? error.response?.data : null,
                    );
                    settledOperationToken.current++;
                    update({
                        state: 'validation_error',
                        result: null,
                        fieldErrors,
                        message:
                            'Check the highlighted fields and submit again. Your request has not been confirmed as saved.',
                    });
                } else if (status === 409) {
                    uncertain.current = true;
                    update({
                        state: 'conflict',
                        result: null,
                        message:
                            'This request identity is already in use. Check the original request before starting another.',
                    });
                } else {
                    uncertain.current = true;
                    update({
                        state: 'outcome_unknown',
                        result: null,
                        message:
                            operation === 'recover' && status === 404
                                ? 'No saved result is available yet. The original submission may still be running. Check again before starting another request.'
                                : status === 422
                                  ? 'The retry was rejected, but an earlier submission may still have saved. Check the original request before changing its details.'
                                  : frozenPayload.current
                                    ? 'The request outcome is unknown. Your submitted details are retained here. Check the original request or retry the same submission.'
                                    : 'The request outcome is unknown. Only its reference is retained here. Check the original request before starting another.',
                    });
                }
                return null;
            } finally {
                if (generation.current === operationGeneration)
                    active.current = null;
            }
        },
        [timeoutMs, update, storageKey, clearPendingReference, actorId],
    );

    const submit = useCallback(
        (payload: FormData): Promise<ItTicketCommandResult | null> => {
            if (
                !mounted.current ||
                active.current ||
                !snapshotRef.current.requestId ||
                !['idle', 'validation_error'].includes(
                    snapshotRef.current.state,
                )
            ) {
                return Promise.resolve(null);
            }
            frozenPayload.current = copyPayload(
                payload,
                snapshotRef.current.requestId,
                actorId,
            );
            return execute('submit');
        },
        [execute, actorId],
    );

    const retry = useCallback((): Promise<ItTicketCommandResult | null> => {
        if (
            !frozenPayload.current ||
            !['outcome_unknown', 'session_expired'].includes(
                snapshotRef.current.state,
            )
        )
            return Promise.resolve(null);
        return execute('submit');
    }, [execute]);

    const recover = useCallback((): Promise<ItTicketCommandResult | null> => {
        if (
            ![
                'idle',
                'validation_error',
                'outcome_unknown',
                'session_expired',
                'conflict',
            ].includes(snapshotRef.current.state)
        )
            return Promise.resolve(null);
        return execute('recover');
    }, [execute]);

    const cancelWait = useCallback(() => {
        if (!active.current) return;
        abortOperation();
        uncertain.current = true;
        update({
            state: 'outcome_unknown',
            result: null,
            message:
                'Stopped waiting. This does not cancel the submitted request; it may still be saved. Check the original request before retrying.',
        });
    }, [abortOperation, update]);

    const reset = useCallback(
        (reason: 'new' | 'discard') => {
            if (!['new', 'discard'].includes(reason)) return;
            abortOperation();
            frozenPayload.current = null;
            uncertain.current = false;
            clearPendingReference();
            update(initialSnapshot());
        },
        [abortOperation, update, clearPendingReference],
    );

    const retainPendingReference = useCallback((): boolean => {
        const { requestId, state } = snapshotRef.current;
        if (
            !storageKey ||
            !requestId ||
            scopeRef.current !== storageKey ||
            !['outcome_unknown', 'session_expired', 'conflict'].includes(state)
        )
            return false;
        try {
            sessionStorage.setItem(storageKey, requestId);
            return sessionStorage.getItem(storageKey) === requestId;
        } catch {
            return false;
        }
    }, [storageKey]);

    const selectDraftRequest = useCallback(
        (requestId: string): boolean => {
            if (
                active.current ||
                frozenPayload.current ||
                !['idle', 'validation_error'].includes(
                    snapshotRef.current.state,
                ) ||
                !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
                    requestId,
                )
            )
                return false;
            update(initialSnapshot(null, requestId));
            return true;
        },
        [update],
    );

    return {
        ...snapshot,
        isBusy:
            snapshot.state === 'submitting' || snapshot.state === 'recovering',
        canEdit:
            snapshot.state === 'idle' || snapshot.state === 'validation_error',
        canRetry:
            frozenPayload.current !== null &&
            ['outcome_unknown', 'session_expired'].includes(snapshot.state),
        canRecover: ['outcome_unknown', 'session_expired', 'conflict'].includes(
            snapshot.state,
        ),
        canForgetReference:
            frozenPayload.current === null &&
            ['outcome_unknown', 'session_expired', 'conflict'].includes(
                snapshot.state,
            ),
        submit,
        retry,
        recover,
        cancelWait,
        reset,
        retainPendingReference,
        selectDraftRequest,
        settledOperationToken: settledOperationToken.current,
    };
}

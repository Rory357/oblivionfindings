import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import type { SetupFields, SetupResource } from './use-setup-memory';

export type SetupCreated = {
    id: number;
    resource: SetupResource;
    request_uuid: string;
    configuration_version: string;
    committed_configuration_version: string;
    replayed: boolean;
};
export type SetupCommandOutcome =
    | SetupCreated
    | { cancelled: true; request_uuid: string };
type State =
    | 'idle'
    | 'validation'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'unknown'
    | 'not_found'
    | 'expired'
    | 'denied'
    | 'conflict'
    | 'committed';
type Snapshot = {
    state: State;
    requestUuid: string;
    result: SetupCreated | null;
    errors: Record<string, string>;
    message: string | null;
    settledToken: number;
};
const uuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
        value,
    );
const markerKey = (actorId: number, resource: SetupResource) =>
    `it.setup.pending-command.v1.actor.${actorId}.${resource}`;
function initial(
    actorId: number | undefined,
    resource: SetupResource,
    active: boolean,
): Snapshot {
    let pending: string | null = null;
    try {
        if (actorId && active)
            pending = sessionStorage.getItem(markerKey(actorId, resource));
    } catch {
        /* The current form still works in RAM. */
    }
    return {
        state: uuid(pending) ? 'unknown' : 'idle',
        requestUuid: uuid(pending) ? pending : crypto.randomUUID(),
        result: null,
        errors: {},
        settledToken: 0,
        message: uuid(pending)
            ? 'An earlier create is unconfirmed. Check its original outcome before creating another record.'
            : null,
    };
}

export function useSetupCreateCommand(options: {
    active: boolean;
    actorId?: number;
    resource: SetupResource;
    timeoutMs?: number;
}) {
    const [value, setValue] = useState(() =>
        initial(options.actorId, options.resource, options.active),
    );
    const snapshot = useRef(value);
    const latest = useRef(options);
    latest.current = options;
    const frozen = useRef<SetupFields | null>(null);
    const uncertain = useRef(value.state === 'unknown');
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const scope = useRef(
        `${options.actorId}:${options.resource}:${options.active}`,
    );
    const update = (patch: Partial<Snapshot>) => {
        snapshot.current = { ...snapshot.current, ...patch };
        setValue(snapshot.current);
    };
    const clearMarker = (
        actorId: number,
        resource: SetupResource,
        requestUuid: string,
    ) => {
        try {
            const key = markerKey(actorId, resource);
            if (sessionStorage.getItem(key) === requestUuid)
                sessionStorage.removeItem(key);
        } catch {
            /* No private fallback storage. */
        }
    };
    useEffect(() => {
        const next = `${options.actorId}:${options.resource}:${options.active}`;
        if (scope.current === next) return;
        scope.current = next;
        epoch.current++;
        controller.current?.abort();
        controller.current = null;
        frozen.current = null;
        const nextValue = initial(
            options.actorId,
            options.resource,
            options.active,
        );
        uncertain.current = nextValue.state === 'unknown';
        snapshot.current = nextValue;
        setValue(nextValue);
    }, [options.active, options.actorId, options.resource]);
    useEffect(
        () => () => {
            epoch.current++;
            controller.current?.abort();
        },
        [],
    );
    const execute = async (
        operation: 'submit' | 'recover' | 'cancel',
    ): Promise<SetupCommandOutcome | null> => {
        const binding = latest.current;
        const requestUuid = snapshot.current.requestUuid;
        if (
            controller.current ||
            !binding.active ||
            !binding.actorId ||
            (operation === 'submit' && !frozen.current)
        )
            return null;
        const wasUncertain = uncertain.current;
        const abort = new AbortController();
        controller.current = abort;
        const operationEpoch = ++epoch.current;
        if (operation === 'submit') {
            try {
                const key = markerKey(binding.actorId, binding.resource);
                const prior = sessionStorage.getItem(key);
                if (uuid(prior) && prior !== requestUuid) {
                    controller.current = null;
                    uncertain.current = true;
                    update({
                        state: 'unknown',
                        requestUuid: prior,
                        message:
                            'Recover the earlier create before starting another.',
                    });
                    frozen.current = null;
                    return null;
                }
                sessionStorage.setItem(key, requestUuid);
            } catch {
                /* RAM retains the exact current command if browser storage is disabled. */
            }
        }
        update({
            state:
                operation === 'submit'
                    ? 'sending'
                    : operation === 'recover'
                      ? 'recovering'
                      : 'cancelling',
            errors: {},
            message: null,
        });
        const timer = setTimeout(() => {
            if (operationEpoch !== epoch.current) return;
            epoch.current++;
            abort.abort();
            controller.current = null;
            uncertain.current = true;
            update({
                state: 'unknown',
                message:
                    'The create wait timed out. Check its original command before retrying.',
            });
        }, options.timeoutMs ?? 15000);
        try {
            const response = await axios.post(
                operation === 'submit'
                    ? `/it/setup/${binding.resource}`
                    : `/it/setup/commands/${requestUuid}/${operation === 'recover' ? 'recover' : 'cancel'}`,
                operation === 'submit'
                    ? {
                          ...structuredClone(frozen.current),
                          actor_user_id: binding.actorId,
                          request_uuid: requestUuid,
                      }
                    : {
                          actor_user_id: binding.actorId,
                          resource: binding.resource,
                      },
                {
                    signal: abort.signal,
                    headers: { Accept: 'application/json' },
                    validateStatus: () => true,
                },
            );
            if (
                operationEpoch !== epoch.current ||
                abort.signal.aborted ||
                scope.current !==
                    `${binding.actorId}:${binding.resource}:${binding.active}` ||
                latest.current.actorId !== binding.actorId ||
                latest.current.resource !== binding.resource ||
                !latest.current.active
            )
                return null;
            const data = response.data?.data;
            const bound =
                data &&
                data.viewer_user_id === binding.actorId &&
                data.resource === binding.resource &&
                data.request_uuid === requestUuid;
            if (
                response.status === 200 &&
                bound &&
                response.data.status === 'committed' &&
                Number.isSafeInteger(data.id) &&
                data.id > 0 &&
                typeof data.replayed === 'boolean' &&
                typeof data.configuration_version === 'string' &&
                typeof data.committed_configuration_version === 'string' &&
                /^[a-f0-9]{64}$/.test(data.configuration_version) &&
                /^[a-f0-9]{64}$/.test(data.committed_configuration_version)
            ) {
                const result: SetupCreated = {
                    id: data.id,
                    resource: binding.resource,
                    request_uuid: requestUuid,
                    configuration_version: data.configuration_version,
                    committed_configuration_version:
                        data.committed_configuration_version,
                    replayed: data.replayed,
                };
                clearMarker(binding.actorId, binding.resource, requestUuid);
                frozen.current = null;
                uncertain.current = false;
                update({
                    state: 'committed',
                    result,
                    message: null,
                    settledToken: snapshot.current.settledToken + 1,
                });
                return result;
            }
            if (
                response.status === 200 &&
                bound &&
                response.data.status === 'cancelled' &&
                data.cancelled === true
            ) {
                clearMarker(binding.actorId, binding.resource, requestUuid);
                frozen.current = null;
                uncertain.current = false;
                update({
                    state: 'idle',
                    requestUuid: crypto.randomUUID(),
                    result: null,
                    message:
                        'The earlier create was cancelled before it saved. You can now review and submit a new create.',
                    settledToken: snapshot.current.settledToken + 1,
                });
                return { cancelled: true, request_uuid: requestUuid };
            }
            if (
                response.status === 200 &&
                bound &&
                operation === 'recover' &&
                response.data.status === 'not_found' &&
                data.retry_same_command === true
            ) {
                uncertain.current = true;
                update({
                    state: 'not_found',
                    message: frozen.current
                        ? 'No saved result is available. Retry the exact retained create or cancel this command.'
                        : 'No saved result is available, and the original fields are not here. Resume their retained browser form, or cancel this command before starting again.',
                });
                return null;
            }
            if (response.status === 403 || response.status === 404) {
                frozen.current = null;
                clearMarker(binding.actorId, binding.resource, requestUuid);
                update({
                    state: 'denied',
                    result: null,
                    message:
                        'This create is no longer available to your current access.',
                });
                return null;
            }
            if (response.status === 401 || response.status === 419) {
                uncertain.current = true;
                update({
                    state: 'expired',
                    message:
                        'Sign in again, then check the original create. Entered values remain concealed.',
                });
                return null;
            }
            if (
                response.status === 422 &&
                operation === 'submit' &&
                !wasUncertain
            ) {
                const errors: Record<string, string> = {};
                for (const [field, messages] of Object.entries(
                    response.data?.errors ?? {},
                )) {
                    const message = Array.isArray(messages)
                        ? messages[0]
                        : messages;
                    if (typeof message === 'string') errors[field] = message;
                }
                clearMarker(binding.actorId, binding.resource, requestUuid);
                frozen.current = null;
                uncertain.current = false;
                update({
                    state: 'validation',
                    errors,
                    message:
                        'The create was rejected. Correct the highlighted details and submit again.',
                    settledToken: snapshot.current.settledToken + 1,
                });
                return null;
            }
            uncertain.current = true;
            update({
                state: response.status === 409 ? 'conflict' : 'unknown',
                message:
                    'The create outcome was not confirmed. Check the original command before changing its details.',
            });
        } catch {
            if (operationEpoch === epoch.current) {
                uncertain.current = true;
                update({
                    state: 'unknown',
                    message:
                        'The create outcome is unknown. Its original command identity is retained for recovery.',
                });
            }
        } finally {
            clearTimeout(timer);
            if (operationEpoch === epoch.current) controller.current = null;
        }
        return null;
    };
    const submit = (fields: SetupFields) => {
        if (!['idle', 'validation'].includes(snapshot.current.state))
            return Promise.resolve(null);
        frozen.current = structuredClone(fields);
        return execute('submit');
    };
    const restore = (
        requestUuid: string,
        fields: SetupFields | null,
        outcomeUnknown: boolean,
    ) => {
        if (
            !uuid(requestUuid) ||
            controller.current ||
            (uncertain.current && snapshot.current.requestUuid !== requestUuid)
        )
            return false;
        frozen.current = fields ? structuredClone(fields) : null;
        uncertain.current = outcomeUnknown;
        update({
            requestUuid,
            state: outcomeUnknown ? 'unknown' : 'idle',
            message: outcomeUnknown
                ? 'The original create is unconfirmed. Check its outcome before another action.'
                : null,
        });
        return true;
    };
    const cancelWait = () => {
        if (!controller.current) return;
        epoch.current++;
        controller.current?.abort();
        controller.current = null;
        uncertain.current = true;
        update({
            state: 'unknown',
            message:
                'Waiting stopped. The create may still finish; check its original outcome.',
        });
    };
    return {
        ...value,
        busy: ['sending', 'recovering', 'cancelling'].includes(value.state),
        canEdit:
            options.active &&
            !!options.actorId &&
            ['idle', 'validation'].includes(value.state),
        canRetry:
            frozen.current !== null &&
            ['unknown', 'not_found', 'expired'].includes(value.state),
        pending: !['idle', 'validation', 'committed', 'denied'].includes(
            value.state,
        ),
        submit,
        restore,
        cancelWait,
        retry: () =>
            ['unknown', 'not_found', 'expired'].includes(snapshot.current.state)
                ? execute('submit')
                : Promise.resolve(null),
        recover: () =>
            ['unknown', 'not_found', 'expired', 'conflict'].includes(
                snapshot.current.state,
            )
                ? execute('recover')
                : Promise.resolve(null),
        cancelCommand: () =>
            ['unknown', 'not_found', 'expired', 'conflict'].includes(
                snapshot.current.state,
            )
                ? execute('cancel')
                : Promise.resolve(null),
        accepts: (requestUuid?: string | null) =>
            !uncertain.current || requestUuid === snapshot.current.requestUuid,
        getSnapshot: () => snapshot.current,
    };
}

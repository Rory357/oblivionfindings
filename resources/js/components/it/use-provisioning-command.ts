import axios from 'axios';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type ProvisioningCommandIdentity = {
    actorId: number;
    kind: 'request' | 'workflow' | 'launch' | 'template' | 'manual';
    targetId: number;
    operation: string;
    requestUuid: string;
};
export type ProvisioningCommandOutcome =
    | { status: 'committed'; resultId: number; version: number; url: string }
    | { status: 'cancelled' }
    | { status: 'not_found' };
type Phase =
    | 'idle'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'unknown'
    | 'validation'
    | 'conflict'
    | 'session'
    | 'denied'
    | 'unavailable'
    | 'committed'
    | 'cancelled'
    | 'not_found';
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const uuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
        value,
    );

export function readProvisioningOutcome(
    value: unknown,
    identity: ProvisioningCommandIdentity,
): ProvisioningCommandOutcome | null {
    if (!record(value) || !record(value.data)) return null;
    const data = value.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.kind !== identity.kind ||
        data.target_id !== identity.targetId ||
        data.operation !== identity.operation ||
        data.request_uuid !== identity.requestUuid
    )
        return null;
    if (value.status === 'cancelled' && data.result_id === undefined)
        return { status: 'cancelled' };
    if (
        value.status === 'not_found' &&
        data.retry_same_command === true &&
        data.result_id === undefined
    )
        return { status: 'not_found' };
    const path =
        identity.kind === 'request' || identity.kind === 'manual'
            ? 'tasks'
            : identity.kind === 'template'
              ? 'templates'
              : 'workflows';
    if (
        value.status !== 'committed' ||
        !positive(data.result_id) ||
        (!['manual', 'launch'].includes(identity.kind) &&
            data.result_id !== identity.targetId) ||
        !positive(data.lock_version) ||
        typeof data.replayed !== 'boolean' ||
        data.url !== '/it/provisioning/' + path + '/' + data.result_id
    )
        return null;
    return {
        status: 'committed',
        resultId: data.result_id,
        version: data.lock_version,
        url: data.url as string,
    };
}

export function newProvisioningUuid(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function')
        return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

/** Only the immutable command identity survives a page reload; private fields stay in RAM. */
export function useProvisioningCommand(
    context: Omit<ProvisioningCommandIdentity, 'requestUuid'>,
) {
    const key =
        'it.provisioning.pending.v1.' +
        context.actorId +
        '.' +
        context.kind +
        '.' +
        context.targetId +
        '.' +
        context.operation;
    const [initial] = useState(() => {
        try {
            const retained = sessionStorage.getItem(key);
            return {
                identity: {
                    ...context,
                    requestUuid: uuid(retained)
                        ? retained
                        : newProvisioningUuid(),
                },
                phase: (uuid(retained) ? 'unknown' : 'idle') as Phase,
            };
        } catch {
            return {
                identity: { ...context, requestUuid: '' },
                phase: 'unavailable' as Phase,
            };
        }
    });
    const [phase, setPhase] = useState<Phase>(initial.phase);
    const [message, setMessage] = useState(
        initial.phase === 'unknown'
            ? 'A previous outcome is unconfirmed. Check it before starting another command.'
            : '',
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [outcome, setOutcome] = useState<ProvisioningCommandOutcome | null>(
        null,
    );
    const identity = initial.identity;
    const active = useRef(true);
    const sequence = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const frozen = useRef<Record<string, unknown> | null>(null);
    const [hasFrozen, setHasFrozen] = useState(false);
    const busy = useRef(false);
    const current = useRef(context);
    useLayoutEffect(() => {
        current.current = context;
    }, [context]);
    const matches = () =>
        current.current.actorId === identity.actorId &&
        current.current.kind === identity.kind &&
        current.current.targetId === identity.targetId &&
        current.current.operation === identity.operation;
    useEffect(() => {
        active.current = true;
        const invalidatePending = () => {
            sequence.current++;
        };
        return () => {
            active.current = false;
            invalidatePending();
            controller.current?.abort();
            frozen.current = null;
        };
    }, []);
    const sameContext =
        context.actorId === identity.actorId &&
        context.kind === identity.kind &&
        context.targetId === identity.targetId &&
        context.operation === identity.operation;
    const concealed = !sameContext || phase === 'denied' || phase === 'session';

    const run = async (action: 'send' | 'recover' | 'cancel') => {
        if (
            busy.current ||
            !matches() ||
            !uuid(identity.requestUuid) ||
            !active.current
        )
            return;
        if (action === 'send' && !frozen.current) return;
        try {
            sessionStorage.setItem(key, identity.requestUuid);
            if (sessionStorage.getItem(key) !== identity.requestUuid)
                throw new Error('Reference unavailable');
        } catch {
            setPhase('unavailable');
            setMessage(
                'The browser cannot retain a recovery reference. Enable session storage before sending.',
            );
            return;
        }
        busy.current = true;
        const token = ++sequence.current;
        const abort = new AbortController();
        controller.current = abort;
        setPhase(
            action === 'send'
                ? 'sending'
                : action === 'recover'
                  ? 'recovering'
                  : 'cancelling',
        );
        setErrors({});
        setMessage(
            action === 'send'
                ? 'Saving the reviewed command…'
                : 'Checking the original command…',
        );
        const base =
            '/it/provisioning/commands/' +
            identity.kind +
            '/' +
            identity.targetId +
            '/' +
            identity.operation;
        const reference = {
            actor_user_id: identity.actorId,
            request_uuid: identity.requestUuid,
        };
        try {
            const options = {
                signal: abort.signal,
                timeout: 30000,
                headers: { Accept: 'application/json' },
            };
            const response =
                action === 'send'
                    ? await axios.post(
                          base,
                          { ...frozen.current, ...reference },
                          options,
                      )
                    : action === 'cancel'
                      ? await axios.post(base + '/cancel', reference, options)
                      : await axios.get(base, {
                            ...options,
                            params: reference,
                        });
            if (!active.current || token !== sequence.current || !matches())
                return;
            const parsed = readProvisioningOutcome(response.data, identity);
            if (!parsed) {
                setPhase('unknown');
                setMessage(
                    'The response did not confirm this command. Check its saved outcome.',
                );
                return;
            }
            setOutcome(parsed);
            setPhase(parsed.status);
            if (
                parsed.status === 'committed' ||
                parsed.status === 'cancelled'
            ) {
                frozen.current = null;
                setHasFrozen(false);
                try {
                    sessionStorage.removeItem(key);
                } catch {
                    /* A retained terminal reference remains recoverable. */
                }
            }
            setMessage(
                parsed.status === 'committed'
                    ? 'The original command is confirmed.'
                    : parsed.status === 'cancelled'
                      ? 'The unsent command is cancelled. No committed work was undone.'
                      : 'No saved result exists for this command. Retry its original details or cancel its identity.',
            );
        } catch (error) {
            if (!active.current || token !== sequence.current || !matches())
                return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : null;
            if (status === 401 || status === 419) {
                setPhase('session');
                setMessage(
                    'Sign in again using the original account, then check this command.',
                );
            } else if (status === 403 || status === 404) {
                frozen.current = null;
                setHasFrozen(false);
                setPhase('denied');
                setMessage(
                    'This account can no longer access this work. Private details are hidden.',
                );
            } else if (status === 409) {
                setPhase('conflict');
                setMessage(
                    'This work or command changed. Check the outcome, then reload the current record before preparing another decision.',
                );
            } else if (
                status === 422 &&
                action === 'send' &&
                axios.isAxiosError(error)
            ) {
                const data: unknown = error.response?.data;
                const entries =
                    record(data) && record(data.errors)
                        ? Object.entries(data.errors).flatMap(
                              ([field, values]) => {
                                  const first = Array.isArray(values)
                                      ? values[0]
                                      : values;
                                  return typeof first === 'string'
                                      ? [[field, first] as const]
                                      : [];
                              },
                          )
                        : [];
                setErrors(Object.fromEntries(entries));
                setPhase('validation');
                setMessage(
                    entries.length
                        ? 'Review the fields below.'
                        : 'The command was not accepted. Review the current work and its requirements.',
                );
            } else {
                setPhase('unknown');
                setMessage(
                    'The result is unconfirmed. Check the original command before retrying.',
                );
            }
        } finally {
            if (token === sequence.current) {
                busy.current = false;
                controller.current = null;
            }
        }
    };
    const canEdit = !concealed && ['idle', 'validation'].includes(phase);
    return {
        phase,
        message,
        errors,
        outcome,
        identity,
        concealed,
        canEdit,
        busy: ['sending', 'recovering', 'cancelling'].includes(phase),
        canRetry: phase === 'not_found' && hasFrozen,
        canRecover: ![
            'idle',
            'committed',
            'cancelled',
            'unavailable',
            'denied',
        ].includes(phase),
        submit: (payload: Record<string, unknown>) => {
            if (!canEdit || busy.current) return;
            frozen.current = structuredClone(payload);
            setHasFrozen(true);
            void run('send');
        },
        recover: () => run('recover'),
        cancel: () => run('cancel'),
        retry: () => (phase === 'not_found' ? run('send') : Promise.resolve()),
        stopWaiting: () => {
            controller.current?.abort();
            sequence.current++;
            busy.current = false;
            controller.current = null;
            setPhase('unknown');
            setMessage(
                'Waiting stopped. The server may still finish; check the original outcome.',
            );
        },
    };
}

import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    handoffBody,
    handoffParameters,
    readHandoffResult,
    validHandoffIdentity,
    type HandoffIdentity,
    type HandoffIntent,
    type HandoffResult,
    type HandoffSelection,
} from './it-control-room-handoff-contract';
import { newItCommentUuid } from './it-ticket-comment-contract';
import { draftRecord } from './it-ticket-draft-contract';

type Stage =
    | 'editing'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'unknown'
    | 'rejected'
    | 'access'
    | 'session'
    | 'unavailable'
    | 'settled';
interface State {
    stage: Stage;
    message: string | null;
    identity: HandoffIdentity | null;
    result: HandoffResult | null;
    errors?: Record<string, string>;
}
const initial = (): State => ({
    stage: 'editing',
    message: null,
    identity: null,
    result: null,
});
const key = (actorId: number, alertId: number) =>
    `it.pending-control-room-handoff.v1.${actorId}:${alertId}`;

/** Store only an opaque identity before sending. Titles, context and review content stay in memory. */
export function useItControlRoomHandoffCommand(
    actorId: number,
    alertId: number,
) {
    const scope = `${actorId}:${alertId}`;
    const [state, setState] = useState<State>(initial);
    const [loadedScope, setLoadedScope] = useState(scope);
    const scopeRef = useRef(scope);
    const epoch = useRef(0);
    const active = useRef<AbortController | null>(null);
    const intent = useRef<Readonly<HandoffIntent> | null>(null);
    const reference = useRef<HandoffIdentity | null>(null);
    useLayoutEffect(() => {
        scopeRef.current = scope;
    });
    useEffect(() => {
        ++epoch.current;
        active.current?.abort();
        active.current = null;
        intent.current = null;
        reference.current = null;
        setLoadedScope(scope);
        try {
            const raw = sessionStorage.getItem(key(actorId, alertId));
            if (raw === null) {
                setState(initial());
            } else {
                const stored: unknown = JSON.parse(raw);
                if (
                    !validHandoffIdentity(stored) ||
                    stored.actorId !== actorId ||
                    stored.alertId !== alertId
                )
                    throw new Error();
                reference.current = stored;
                setState({
                    ...initial(),
                    stage: 'unknown',
                    identity: stored,
                    message:
                        'A previous IT handoff is not confirmed. Check its result before making another change.',
                });
            }
        } catch {
            setState({
                ...initial(),
                stage: 'unavailable',
                message:
                    'The browser recovery reference is unavailable. Restore session storage before preparing IT work.',
            });
        }
        return () => {
            ++epoch.current;
            active.current?.abort();
            active.current = null;
        };
    }, [actorId, alertId, scope]);

    const run = useCallback(
        async (mode: 'send' | 'read' | 'cancel') => {
            const identity = reference.current;
            if (
                !identity ||
                active.current ||
                scopeRef.current !== scope ||
                (mode === 'send' && !intent.current)
            )
                return;
            const controller = new AbortController();
            active.current = controller;
            const token = ++epoch.current;
            const live = () =>
                epoch.current === token && scopeRef.current === scope;
            setState((previous) => ({
                ...previous,
                stage:
                    mode === 'send'
                        ? 'sending'
                        : mode === 'read'
                          ? 'recovering'
                          : 'cancelling',
                message: null,
            }));
            try {
                const params = handoffParameters(identity);
                const path = `/it/control-room/alerts/${alertId}/handoff/commands/${identity.requestUuid}`;
                const config = {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                };
                const response =
                    mode === 'send'
                        ? await axios.post(
                              `/it/control-room/alerts/${alertId}/handoff`,
                              handoffBody(intent.current!),
                              config,
                          )
                        : mode === 'cancel'
                          ? await axios.post(`${path}/cancel`, params, config)
                          : await axios.get(path, { ...config, params });
                if (!live()) return;
                const result = readHandoffResult(
                    response.data,
                    identity,
                    window.location.origin,
                );
                if (!result) throw new Error('unconfirmed');
                if (result.status === 'unconfirmed') {
                    setState({
                        stage: 'unknown',
                        identity,
                        result: null,
                        message:
                            'No result is confirmed yet. Check again, retry the unchanged request or cancel the pending change.',
                    });
                    return;
                }
                let message: string | null = null;
                try {
                    sessionStorage.removeItem(key(actorId, alertId));
                } catch {
                    message =
                        'The outcome is confirmed, but its browser recovery reference could not be cleared.';
                }
                reference.current = null;
                intent.current = null;
                setState({ stage: 'settled', identity, result, message });
            } catch (error) {
                if (!live()) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                if (
                    status === 401 ||
                    status === 419 ||
                    status === 403 ||
                    status === 404
                ) {
                    intent.current = null;
                    setState({
                        stage:
                            status === 401 || status === 419
                                ? 'session'
                                : 'access',
                        identity,
                        result: null,
                        message:
                            status === 401 || status === 419
                                ? 'Sign in again to check this change.'
                                : 'Access to this alert or selected IT work is no longer available.',
                    });
                    return;
                }
                const data: unknown = axios.isAxiosError(error)
                    ? error.response?.data
                    : null;
                if (
                    mode === 'send' &&
                    (status === 422 ||
                        (status === 409 &&
                            draftRecord(data) &&
                            data.code === 'stale_ticket'))
                ) {
                    try {
                        sessionStorage.removeItem(key(actorId, alertId));
                    } catch {
                        setState({
                            stage: 'unknown',
                            identity,
                            result: null,
                            message:
                                'The request was rejected, but its recovery reference could not be cleared. Check its result before continuing.',
                        });
                        return;
                    }
                    reference.current = null;
                    intent.current = null;
                    setState({
                        stage: 'rejected',
                        identity: null,
                        result: null,
                        message:
                            status === 409
                                ? 'The selected ticket changed during review. Refresh the records and review the handoff again.'
                                : draftRecord(data) &&
                                    typeof data.message === 'string'
                                  ? data.message
                                  : 'This handoff could not be saved. Refresh and review the records.',
                        errors:
                            draftRecord(data) && draftRecord(data.errors)
                                ? Object.fromEntries(
                                      Object.entries(data.errors).flatMap(
                                          ([field, messages]) =>
                                              Array.isArray(messages) &&
                                              typeof messages[0] === 'string'
                                                  ? [[field, messages[0]]]
                                                  : [],
                                      ),
                                  )
                                : {},
                    });
                    return;
                }
                setState({
                    stage: 'unknown',
                    identity,
                    result: null,
                    message:
                        'The outcome is unknown. Check the saved result before making another change.',
                });
            } finally {
                if (live()) active.current = null;
            }
        },
        [actorId, alertId, scope],
    );

    const send = useCallback(
        (selection: HandoffSelection) => {
            if (
                reference.current ||
                active.current ||
                state.stage === 'unavailable' ||
                state.stage === 'access' ||
                state.stage === 'session'
            )
                return;
            try {
                const next: HandoffIntent = {
                    ...selection,
                    actorId,
                    alertId,
                    requestUuid: newItCommentUuid(),
                };
                if (
                    !validHandoffIdentity(next) ||
                    !/^[a-f0-9]{64}$/.test(next.alertVersion)
                )
                    throw new Error();
                const identity: HandoffIdentity = {
                    actorId,
                    alertId,
                    requestUuid: next.requestUuid,
                };
                sessionStorage.setItem(
                    key(actorId, alertId),
                    JSON.stringify(identity),
                );
                reference.current = identity;
                intent.current = Object.freeze(next);
                setState({ ...initial(), identity });
                void run('send');
            } catch {
                setState({
                    ...initial(),
                    stage: 'unavailable',
                    message:
                        'The browser could not safely retain a recovery reference. No request was sent.',
                });
            }
        },
        [actorId, alertId, state.stage, run],
    );
    const stopWaiting = () => {
        ++epoch.current;
        active.current?.abort();
        active.current = null;
        setState((previous) => ({
            ...previous,
            stage: 'unknown',
            message:
                'Stopped waiting. This does not cancel the server request; check its result or cancel the pending change.',
        }));
    };
    return {
        ...(loadedScope === scope
            ? state
            : { ...initial(), stage: 'access' as const }),
        send,
        conceal: (kind: 'access' | 'session') => {
            ++epoch.current;
            active.current?.abort();
            active.current = null;
            intent.current = null;
            setState({
                stage: kind,
                identity: reference.current,
                result: null,
                message:
                    kind === 'session'
                        ? 'Sign in again to check this handoff.'
                        : 'Access to this IT handoff is no longer available.',
            });
        },
        reset: () => {
            if (
                !reference.current &&
                !active.current &&
                state.stage === 'rejected'
            )
                setState(initial());
        },
        check: () => void run('read'),
        cancel: () => void run('cancel'),
        retry: () => void run('send'),
        stopWaiting,
        canRetry: loadedScope === scope && intent.current !== null,
        busy: ['sending', 'recovering', 'cancelling'].includes(state.stage),
    };
}

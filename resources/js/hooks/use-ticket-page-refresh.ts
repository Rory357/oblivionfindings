import { router } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';

export type TicketRefreshAccess = 'session' | 'access' | 'actor';
const REFRESH_HEADER = 'X-IT-Ticket-Refresh';
let refreshSequence = 0;

function requestHeader(headers: unknown): unknown {
    if (!headers || typeof headers !== 'object') return undefined;
    if ('get' in headers && typeof headers.get === 'function')
        return headers.get(REFRESH_HEADER);
    return Object.entries(headers).find(
        ([key]) => key.toLowerCase() === REFRESH_HEADER.toLowerCase(),
    )?.[1];
}

/** Read current props without remounting work, with a queued read after a concurrent commit. */
export function useTicketPageRefresh(actorId: number | null, ticketId: number) {
    const scope = `${actorId}:${ticketId}`;
    const current = useRef(scope);
    const authorization = useRef<{
        scope: string;
        access: TicketRefreshAccess | null;
        holdAcrossScope: boolean;
    }>({ scope, access: null, holdAcrossScope: false });
    useLayoutEffect(() => {
        current.current = scope;
    }, [scope]);
    const operation = useRef<{
        cancel?: () => void;
        timer?: ReturnType<typeof setTimeout>;
        removeInvalid?: () => void;
        epoch: number;
        active: boolean;
        queued: boolean;
    }>({ epoch: 0, active: false, queued: false });
    const [state, setState] = useState({
        scope,
        pending: false,
        error: null as string | null,
        access: null as TicketRefreshAccess | null,
        holdAcrossScope: false,
    });
    useEffect(() => {
        const request = operation.current;
        return () => {
            ++request.epoch;
            request.active = false;
            request.queued = false;
            clearTimeout(request.timer);
            request.removeInvalid?.();
            request.removeInvalid = undefined;
            request.cancel?.();
            request.cancel = undefined;
        };
    }, [scope]);

    const refresh = useCallback(
        function refresh() {
            if (!actorId || current.current !== scope) return;
            const request = operation.current;
            if (request.active) {
                request.queued = true;
                return;
            }
            request.active = true;
            const epoch = ++request.epoch;
            const correlation = `${Date.now()}:${++refreshSequence}`;
            let finished = false;
            const active = () =>
                !finished &&
                request.epoch === epoch &&
                current.current === scope;
            const settle = (
                error: string | null,
                access?: TicketRefreshAccess | null,
                holdAcrossScope = access === 'actor',
            ) => {
                if (!active()) return;
                finished = true;
                const previousAccess = authorization.current;
                const resolvedAccess =
                    access === undefined
                        ? previousAccess.scope === scope ||
                          previousAccess.holdAcrossScope
                            ? previousAccess.access
                            : null
                        : access;
                const held =
                    access === undefined
                        ? previousAccess.holdAcrossScope
                        : holdAcrossScope;
                authorization.current = {
                    scope,
                    access: resolvedAccess,
                    holdAcrossScope: held,
                };
                const queued = request.queued && !resolvedAccess;
                request.active = false;
                request.queued = false;
                clearTimeout(request.timer);
                request.removeInvalid?.();
                request.removeInvalid = undefined;
                request.cancel = undefined;
                setState({
                    scope,
                    pending: false,
                    error,
                    access: resolvedAccess,
                    holdAcrossScope: held,
                });
                // A successful mutation may have happened after the first GET took
                // its snapshot. One later read must include that mutation.
                if (queued)
                    queueMicrotask(() => {
                        if (
                            current.current === scope &&
                            request.epoch === epoch
                        )
                            refresh();
                    });
            };
            const conceal = (
                access: TicketRefreshAccess,
                holdAcrossScope = access === 'actor',
            ) => {
                if (!active()) return;
                authorization.current = { scope, access, holdAcrossScope };
                request.queued = false;
                setState({
                    scope,
                    pending: true,
                    access,
                    error: null,
                    holdAcrossScope,
                });
            };
            setState((previous) => ({
                scope,
                pending: true,
                error: null,
                access:
                    previous.scope === scope || previous.holdAcrossScope
                        ? previous.access
                        : null,
                holdAcrossScope: previous.holdAcrossScope,
            }));
            request.timer = setTimeout(() => {
                const cancel = request.cancel;
                settle(
                    'The conversation could not be refreshed in time. Your entered work is retained. Try Refresh again.',
                );
                cancel?.();
            }, 20000);
            // Only this unique GET may change access state or suppress Inertia's
            // default error dialog; unrelated responses are never intercepted.
            request.removeInvalid = router.on('invalid', (event) => {
                if (
                    !active() ||
                    requestHeader(event.detail.response.config?.headers) !==
                        correlation
                )
                    return;
                const status = event.detail.response.status;
                const access =
                    status === 401 || status === 419
                        ? 'session'
                        : status === 403 || status === 404
                          ? 'access'
                          : null;
                settle(
                    access
                        ? 'Current ticket access could not be confirmed.'
                        : 'The conversation could not be refreshed. Your entered work is retained. Try Refresh again.',
                    access ?? undefined,
                );
                return false;
            });
            try {
                router.reload({
                    headers: { [REFRESH_HEADER]: correlation },
                    // Refresh every canonical prop: narrowed permissions also
                    // change linked records, KB hints and scoped choices.
                    onCancelToken: (token) => {
                        if (active()) request.cancel = () => token.cancel();
                    },
                    onBeforeUpdate: (page) => {
                        if (!active()) return;
                        const auth = page.props.auth as
                            | { user?: { id?: number } | null }
                            | undefined;
                        if (!auth?.user?.id) conceal('session');
                        else if (
                            auth.user.id !== actorId ||
                            page.props.viewer_user_id !== actorId
                        )
                            conceal('actor');
                        else {
                            const ticket = page.props.ticket;
                            if (
                                page.component !== 'it/tickets/show' ||
                                !ticket ||
                                typeof ticket !== 'object' ||
                                !('id' in ticket) ||
                                ticket.id !== ticketId
                            )
                                conceal('access', true);
                        }
                    },
                    onSuccess: (page) => {
                        const ticket = page.props.ticket;
                        const auth = page.props.auth as
                            | { user?: { id?: number } | null }
                            | undefined;
                        if (!auth?.user?.id) {
                            settle(
                                'Sign in with your original account before continuing.',
                                'session',
                            );
                            return;
                        }
                        if (
                            auth.user.id !== actorId ||
                            page.props.viewer_user_id !== actorId
                        ) {
                            settle(
                                'Your signed-in account changed. Reload the page before continuing.',
                                'actor',
                            );
                            return;
                        }
                        const matches =
                            page.component === 'it/tickets/show' &&
                            !!ticket &&
                            typeof ticket === 'object' &&
                            'id' in ticket &&
                            ticket.id === ticketId;
                        settle(
                            matches
                                ? null
                                : 'The current ticket could not be confirmed. Reload the page before continuing.',
                            matches ? null : 'access',
                            !matches,
                        );
                    },
                    onError: () =>
                        settle(
                            'The conversation could not be refreshed. Your entered work is retained. Try Refresh again.',
                        ),
                    onCancel: () =>
                        settle(
                            'Refresh stopped. The existing conversation and entered work are retained.',
                        ),
                    onFinish: () =>
                        settle(
                            'The conversation was not confirmed. Your entered work is retained. Try Refresh again.',
                        ),
                });
            } catch {
                settle(
                    'The conversation could not be refreshed. Your entered work is retained. Try Refresh again.',
                );
            }
        },
        [actorId, ticketId, scope],
    );
    return {
        refresh,
        pending: state.scope === scope && state.pending,
        error: state.scope === scope ? state.error : null,
        requiresReload: state.holdAcrossScope,
        // Actor changes require an explicit fresh page after old work is purged.
        access: state.holdAcrossScope
            ? state.access
            : state.scope === scope
              ? state.access
              : null,
    };
}

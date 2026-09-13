import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { draftRecord } from './it-ticket-draft-contract';
import {
    readItWorkTaskHistoryPage,
    type ItWorkTaskHistoryPage,
} from './it-work-task-lifecycle';

export function useItWorkTaskHistory({
    actorId,
    ticketId,
    taskId,
    enabled,
    onAccessLost,
    onSessionExpired,
    timeoutMs = 25000,
}: {
    actorId: number;
    ticketId: number;
    taskId: number | null;
    enabled: boolean;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
    timeoutMs?: number;
}) {
    const scope = `${actorId}:${ticketId}:${taskId}`;
    const latest = useRef({ scope, enabled, onAccessLost, onSessionExpired });
    const epoch = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const [state, setState] = useState<{
        scope: string;
        page: ItWorkTaskHistoryPage | null;
        busy: boolean;
        error: string;
        concealed: boolean;
    }>({ scope, page: null, busy: false, error: '', concealed: false });
    useLayoutEffect(() => {
        latest.current = { scope, enabled, onAccessLost, onSessionExpired };
    }, [scope, enabled, onAccessLost, onSessionExpired]);
    const invalidate = useCallback(() => {
        ++epoch.current;
        controller.current?.abort();
        controller.current = null;
    }, []);
    useEffect(() => {
        invalidate();
        setState({
            scope,
            page: null,
            busy: false,
            error: '',
            concealed: false,
        });
        return invalidate;
    }, [scope, enabled, invalidate]);
    const current =
        state.scope === scope && enabled
            ? state
            : { scope, page: null, busy: false, error: '', concealed: false };
    const cancel = () => {
        invalidate();
        setState((old) => ({
            ...old,
            busy: false,
            error: 'History check cancelled. You can try again.',
        }));
    };
    const load = async (older = false) => {
        if (
            !enabled ||
            taskId === null ||
            current.busy ||
            (older &&
                (!current.page?.history.has_more ||
                    current.page.history.next_before_sequence === null))
        )
            return null;
        const previous = older ? current.page : null;
        const beforeSequence =
            previous?.history.next_before_sequence ?? undefined;
        const nonce = crypto.randomUUID();
        const identity = { actorId, ticketId, taskId, nonce, beforeSequence };
        const operation = ++epoch.current;
        controller.current?.abort();
        const abort = new AbortController();
        controller.current = abort;
        let timedOut = false;
        const timer = window.setTimeout(() => {
            timedOut = true;
            abort.abort();
        }, timeoutMs);
        const active = () =>
            operation === epoch.current &&
            latest.current.enabled &&
            latest.current.scope === scope;
        setState((old) => ({ ...old, scope, busy: true, error: '' }));
        try {
            const response = await axios.get(
                `/it/tickets/${ticketId}/tasks/${taskId}/history`,
                {
                    params: {
                        actor_user_id: actorId,
                        review_nonce: nonce,
                        ...(beforeSequence
                            ? { before_sequence: beforeSequence }
                            : {}),
                    },
                    headers: { Accept: 'application/json' },
                    signal: abort.signal,
                },
            );
            if (!active() || abort.signal.aborted) return null;
            if (
                draftRecord(response.data) &&
                draftRecord(response.data.data) &&
                Number.isSafeInteger(response.data.data.viewer_user_id) &&
                response.data.data.viewer_user_id !== actorId
            ) {
                setState({
                    scope,
                    page: null,
                    busy: false,
                    concealed: true,
                    error: 'The signed-in account changed. Task history has been removed.',
                });
                latest.current.onAccessLost?.();
                return null;
            }
            const page =
                response.status === 200
                    ? readItWorkTaskHistoryPage(response.data, identity)
                    : null;
            if (!page)
                throw new Error(
                    'Unexpected task history response. Try checking again.',
                );
            if (
                previous &&
                (page.lock_version !== previous.lock_version ||
                    page.current_completion_id !==
                        previous.current_completion_id ||
                    page.history.total_count !== previous.history.total_count ||
                    page.history.entries.some((entry) =>
                        previous.history.entries.some(
                            (old) => old.id === entry.id,
                        ),
                    ))
            )
                throw new Error(
                    'Task history changed. Refresh history before loading more.',
                );
            const combined = previous
                ? {
                      ...page,
                      history: {
                          ...page.history,
                          entries: [
                              ...previous.history.entries,
                              ...page.history.entries,
                          ],
                      },
                  }
                : page;
            setState({
                scope,
                page: combined,
                busy: false,
                error: '',
                concealed: false,
            });
            return page;
        } catch (error) {
            if (!active()) return null;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : null;
            const denied = status === 403 || status === 404;
            const session = status === 401 || status === 419;
            setState((old) => ({
                ...old,
                scope,
                page: denied || session ? null : old.page,
                busy: false,
                concealed: denied || session || old.concealed,
                error: denied
                    ? 'Current access to this task history is unavailable.'
                    : session
                      ? 'Sign in again, then check task history.'
                      : timedOut
                        ? 'The history check timed out. Try again.'
                        : error instanceof Error
                          ? error.message
                          : 'Task history could not be checked. Try again.',
            }));
            if (denied) latest.current.onAccessLost?.();
            if (session) latest.current.onSessionExpired?.();
            return null;
        } finally {
            window.clearTimeout(timer);
            if (controller.current === abort) controller.current = null;
        }
    };
    return { ...current, load, cancel };
}

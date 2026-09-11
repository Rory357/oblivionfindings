import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { isItApprovalRecord, type ItApprovalRecord } from './it-approval-work';
import { draftRecord } from './it-ticket-draft-contract';

interface ApprovalHistoryPage {
    version: number;
    records: ItApprovalRecord[];
    page: number;
    total: number;
    nextPage: number | null;
}
export function readItApprovalHistory(
    value: unknown,
    identity: {
        actorId: number;
        ticketId: number;
        nonce: string;
        page: number;
        version?: number;
        targetId?: number;
    },
): ApprovalHistoryPage | null {
    if (
        !draftRecord(value) ||
        value.viewer_user_id !== identity.actorId ||
        value.ticket_id !== identity.ticketId ||
        value.review_nonce !== identity.nonce ||
        !Number.isSafeInteger(value.lock_version) ||
        Number(value.lock_version) < 1 ||
        (identity.version !== undefined &&
            value.lock_version !== identity.version) ||
        !draftRecord(value.history)
    )
        return null;
    const history = value.history;
    const targeted = identity.targetId !== undefined;
    if (
        (targeted
            ? !Number.isSafeInteger(history.page) ||
              Number(history.page) < 1 ||
              value.target_approval_id !== identity.targetId
            : history.page !== identity.page) ||
        history.per_page !== 10 ||
        !Number.isSafeInteger(history.total) ||
        Number(history.total) < 0 ||
        !Array.isArray(history.records) ||
        history.records.length > 10 ||
        !history.records.every(isItApprovalRecord) ||
        new Set(history.records.map((record) => record.id)).size !==
            history.records.length ||
        (history.next_page !== null &&
            history.next_page !== Number(history.page) + 1) ||
        (targeted &&
            !history.records.some((record) => record.id === identity.targetId))
    )
        return null;
    const total = Number(history.total);
    const pageNumber = Number(history.page);
    if (
        history.records.length !==
            Math.min(10, Math.max(0, total - (pageNumber - 1) * 10)) ||
        history.next_page !== (total > pageNumber * 10 ? pageNumber + 1 : null)
    )
        return null;
    return {
        version: Number(value.lock_version),
        records: structuredClone(history.records),
        page: pageNumber,
        total,
        nextPage: history.next_page as number | null,
    };
}
export function useItApprovalHistory({
    actorId,
    ticketId,
    enabled,
    targetId,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number;
    ticketId: number;
    enabled: boolean;
    targetId?: number;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}) {
    const scope = `${actorId}:${ticketId}:${targetId ?? 'all'}`;
    const latest = useRef({ scope, enabled, onAccessLost, onSessionExpired });
    const epoch = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const [state, setState] = useState<{
        scope: string;
        page: ApprovalHistoryPage | null;
        busy: boolean;
        error: string;
    }>({ scope, page: null, busy: false, error: '' });
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
        setState({ scope, page: null, busy: false, error: '' });
        return invalidate;
    }, [scope, enabled, invalidate]);
    const current =
        enabled && state.scope === scope
            ? state
            : { scope, page: null, busy: false, error: '' };
    const load = async (next = false) => {
        if (!enabled || controller.current || (next && !current.page?.nextPage))
            return;
        const previous = next ? current.page : null;
        const nonce = crypto.randomUUID();
        const identity = {
            actorId,
            ticketId,
            nonce,
            page: previous?.nextPage ?? 1,
            ...(previous ? { version: previous.version } : {}),
            ...(!next && targetId !== undefined ? { targetId } : {}),
        };
        const token = ++epoch.current;
        const abort = new AbortController();
        controller.current = abort;
        const valid = () =>
            token === epoch.current &&
            latest.current.scope === scope &&
            latest.current.enabled;
        setState({ scope, page: null, busy: true, error: '' });
        try {
            const response = await axios.get(
                `/it/tickets/${ticketId}/approval-history`,
                {
                    signal: abort.signal,
                    timeout: 25000,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    params: {
                        actor_user_id: actorId,
                        review_nonce: nonce,
                        page: identity.page,
                        ...(identity.targetId !== undefined
                            ? { approval_id: identity.targetId }
                            : {}),
                        ...(previous
                            ? { expected_version: previous.version }
                            : {}),
                    },
                },
            );
            if (!valid()) return;
            if (
                draftRecord(response.data) &&
                typeof response.data.viewer_user_id === 'number' &&
                response.data.viewer_user_id !== actorId
            ) {
                setState({
                    scope,
                    page: null,
                    busy: false,
                    error: 'Your account changed. Approval history was removed.',
                });
                latest.current.onAccessLost();
                return;
            }
            const page =
                response.status === 200
                    ? readItApprovalHistory(response.data, identity)
                    : null;
            setState({
                scope,
                page,
                busy: false,
                error: page
                    ? ''
                    : 'Approval history could not be confirmed. Retry the history check.',
            });
        } catch (error) {
            if (!valid()) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            setState({
                scope,
                page: null,
                busy: false,
                error:
                    status === 409
                        ? 'The approval history changed. Refresh from the first page.'
                        : status === 401 || status === 419
                          ? 'Sign in with the same account, then reload approval history.'
                          : 'Approval history could not be loaded. Retry the history check.',
            });
            if (status === 403 || status === 404) latest.current.onAccessLost();
            if (status === 401 || status === 419)
                latest.current.onSessionExpired?.();
        } finally {
            if (valid()) controller.current = null;
        }
    };
    return {
        ...current,
        load,
        cancel: () => {
            invalidate();
            setState({
                scope,
                page: null,
                busy: false,
                error: 'History check cancelled. You can try again.',
            });
        },
    };
}

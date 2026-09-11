import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    readItMergePreview,
    type ItMergePreview,
    type ItMergePreviewIdentity,
} from './it-ticket-merge-preview';

type Failure = 'session' | 'access' | 'stale' | 'failed' | 'cancelled' | null;
type Selection = Omit<ItMergePreviewIdentity, 'nonce'>;

/** A cancellable read of the exact selected pair; changing selection conceals the old review. */
export function useItTicketMergePreview(selection: Selection, enabled = true) {
    const { actorId, sourceId, targetId, sourceVersion, targetVersion } =
        selection;
    const scope = `${actorId}:${sourceId}:${targetId}:${sourceVersion}:${targetVersion}`;
    const latest = useRef({ scope, enabled });
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const [state, setState] = useState<{
        scope: string;
        busy: boolean;
        preview: ItMergePreview | null;
        failure: Failure;
    }>({ scope, busy: false, preview: null, failure: null });
    useLayoutEffect(() => {
        latest.current = { scope, enabled };
    }, [scope, enabled]);
    const invalidate = useCallback(() => {
        ++epoch.current;
        controller.current?.abort();
        controller.current = null;
    }, []);
    useEffect(() => {
        invalidate();
        setState({ scope, busy: false, preview: null, failure: null });
        return invalidate;
    }, [scope, enabled, invalidate]);
    const load = useCallback(async () => {
        if (
            !enabled ||
            !latest.current.enabled ||
            latest.current.scope !== scope ||
            controller.current ||
            ![actorId, sourceId, targetId, sourceVersion, targetVersion].every(
                (value) => Number.isSafeInteger(value) && value > 0,
            )
        )
            return null;
        const request = new AbortController();
        controller.current = request;
        const token = ++epoch.current;
        const valid = () =>
            latest.current.enabled &&
            latest.current.scope === scope &&
            epoch.current === token;
        setState({ scope, busy: true, preview: null, failure: null });
        try {
            const identity = {
                actorId,
                sourceId,
                targetId,
                sourceVersion,
                targetVersion,
                nonce: crypto.randomUUID(),
            };
            const response = await axios.get(
                `/it/tickets/${sourceId}/merge-preview`,
                {
                    signal: request.signal,
                    timeout: 25000,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    params: {
                        actor_user_id: actorId,
                        target_ticket_id: targetId,
                        source_version: sourceVersion,
                        target_version: targetVersion,
                        review_nonce: identity.nonce,
                    },
                },
            );
            if (!valid()) return null;
            const preview =
                response.status === 200
                    ? readItMergePreview(response.data, identity)
                    : null;
            setState({
                scope,
                busy: false,
                preview,
                failure: preview ? null : 'failed',
            });
            return preview;
        } catch (error) {
            if (!valid()) return null;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            const failure: Failure =
                status === 401 || status === 419
                    ? 'session'
                    : status === 403 || status === 404
                      ? 'access'
                      : status === 409
                        ? 'stale'
                        : 'failed';
            setState({ scope, busy: false, preview: null, failure });
            return null;
        } finally {
            if (valid()) controller.current = null;
        }
    }, [
        actorId,
        sourceId,
        targetId,
        sourceVersion,
        targetVersion,
        scope,
        enabled,
    ]);
    return {
        ...(enabled && state.scope === scope
            ? state
            : { busy: false, preview: null, failure: null }),
        load,
        reset: () => {
            invalidate();
            setState({ scope, busy: false, preview: null, failure: null });
        },
        cancel: () => {
            invalidate();
            setState({
                scope,
                busy: false,
                preview: null,
                failure: 'cancelled',
            });
        },
    };
}

import { useCallback, useEffect, useRef, useState } from 'react';

type PrivacyStatus = { active: boolean; access_fingerprint?: string | null };
type Options = {
    statusUrl?: string | null;
    initialActive?: boolean;
    fingerprint?: string | null;
    intervalMs?: number;
    onAccessEnded?: () => void;
};

export function usePersonalLocationPrivacy({
    statusUrl,
    fingerprint,
    initialActive = false,
    intervalMs = 30_000,
    onAccessEnded,
}: Options) {
    const context = `${statusUrl ?? ''}:${fingerprint ?? ''}`;
    const [state, setState] = useState({
        context,
        active: initialActive,
        checking: Boolean(statusUrl),
        message: null as string | null,
    });
    const generation = useRef(0);
    const current = useRef(context);
    const mounted = useRef(false);
    const ended = useRef(false);
    const controller = useRef<AbortController | null>(null);
    const callback = useRef(onAccessEnded);
    useEffect(() => {
        callback.current = onAccessEnded;
    }, [onAccessEnded]);

    const endAccess = useCallback((message: string) => {
        if (!mounted.current) return;
        generation.current++;
        controller.current?.abort();
        setState({
            context: current.current,
            active: false,
            checking: false,
            message,
        });
        if (!ended.current) {
            ended.current = true;
            callback.current?.();
        }
    }, []);

    const recheck = useCallback(async () => {
        if (!mounted.current || current.current !== context || ended.current)
            return false;
        if (!statusUrl) {
            endAccess('Location access cannot be revalidated.');
            return false;
        }
        const request = ++generation.current;
        controller.current?.abort();
        const abort = new AbortController();
        controller.current = abort;
        const relevant = () =>
            mounted.current &&
            generation.current === request &&
            current.current === context &&
            !abort.signal.aborted &&
            !ended.current;
        setState((previous) => ({ ...previous, checking: true }));
        try {
            const response = await fetch(statusUrl, {
                signal: abort.signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!relevant()) return false;
            if (!response.ok) {
                endAccess(
                    response.status === 403
                        ? 'Location access has ended.'
                        : 'Location access could not be revalidated and is hidden.',
                );
                return false;
            }
            const status = (await response.json()) as PrivacyStatus;
            if (!relevant()) return false;
            if (
                !status.active ||
                (fingerprint && status.access_fingerprint !== fingerprint)
            ) {
                endAccess(
                    'Tracking consent or the personal-tracker assignment has ended. Reload this client to check current access.',
                );
                return false;
            }
            setState({ context, active: true, checking: false, message: null });
            return true;
        } catch {
            if (relevant())
                endAccess(
                    'Location access could not be revalidated and is hidden.',
                );
            return false;
        } finally {
            if (relevant())
                setState((previous) => ({ ...previous, checking: false }));
        }
    }, [context, statusUrl, fingerprint, endAccess]);

    useEffect(() => {
        current.current = context;
        mounted.current = true;
        ended.current = false;
        setState({
            context,
            active: false,
            checking: Boolean(statusUrl),
            message: null,
        });
        void recheck();
        const timer = window.setInterval(() => void recheck(), intervalMs);
        const focus = () => void recheck();
        const visibility = () => {
            if (document.visibilityState === 'visible') void recheck();
        };
        window.addEventListener('focus', focus);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            mounted.current = false;
            // This counter invalidates all in-flight requests; it is not a DOM ref.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            generation.current++;
            controller.current?.abort();
            window.clearInterval(timer);
            window.removeEventListener('focus', focus);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [context, statusUrl, intervalMs, recheck]);

    // Hide synchronously on route/assignment changes, before effects clean up old requests.
    return {
        active: state.context === context && state.active,
        checking: state.context !== context || state.checking,
        message: state.context === context ? state.message : null,
        recheck,
        endAccess,
    };
}

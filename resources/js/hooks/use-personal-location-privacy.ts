import { useCallback, useEffect, useRef, useState } from 'react';

type PrivacyStatus = {
    active: boolean;
    checked_at?: string;
    retention_days?: number | null;
    export_allowed?: boolean;
};

type Options = {
    statusUrl?: string | null;
    initialActive?: boolean;
    intervalMs?: number;
    onAccessEnded?: () => void;
};

export function usePersonalLocationPrivacy({
    statusUrl,
    initialActive = false,
    intervalMs = 30_000,
    onAccessEnded,
}: Options) {
    const [active, setActive] = useState(initialActive);
    const [checking, setChecking] = useState(Boolean(statusUrl));
    const [message, setMessage] = useState<string | null>(null);
    const endedRef = useRef(false);
    const onAccessEndedRef = useRef(onAccessEnded);

    useEffect(() => {
        onAccessEndedRef.current = onAccessEnded;
    }, [onAccessEnded]);

    const endAccess = useCallback((nextMessage: string) => {
        setActive(false);
        setMessage(nextMessage);
        if (!endedRef.current) {
            endedRef.current = true;
            onAccessEndedRef.current?.();
        }
    }, []);

    const recheck = useCallback(async () => {
        if (!statusUrl) {
            endAccess('Location access cannot be revalidated.');
            setChecking(false);
            return false;
        }

        setChecking(true);
        try {
            const response = await fetch(statusUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                endAccess(
                    response.status === 403
                        ? 'Location access has ended.'
                        : 'Location access could not be revalidated and is hidden.',
                );
                return false;
            }

            const status = (await response.json()) as PrivacyStatus;
            if (!status.active) {
                endAccess(
                    'Tracking consent or the personal-tracker assignment has ended.',
                );
                return false;
            }

            endedRef.current = false;
            setActive(true);
            setMessage(null);
            return true;
        } catch {
            endAccess(
                'Location access could not be revalidated and is hidden.',
            );
            return false;
        } finally {
            setChecking(false);
        }
    }, [endAccess, statusUrl]);

    useEffect(() => {
        void recheck();

        const interval = window.setInterval(() => void recheck(), intervalMs);
        const onFocus = () => void recheck();
        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible') void recheck();
        };

        window.addEventListener('focus', onFocus);
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.clearInterval(interval);
            window.removeEventListener('focus', onFocus);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [intervalMs, recheck]);

    return { active, checking, message, recheck, endAccess };
}

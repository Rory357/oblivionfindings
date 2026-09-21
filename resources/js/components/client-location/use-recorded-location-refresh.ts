import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/** Refresh existing reports only; never send a device command. */
export function useRecordedLocationRefresh(
    enabled: boolean,
    accessKey: string,
) {
    useEffect(() => {
        if (!enabled) return;
        let active = true;
        let pending: { cancel?: () => void } | null = null;
        const cancel = () => {
            const old = pending;
            pending = null;
            old?.cancel?.();
        };
        const refresh = () => {
            if (!active || pending || document.visibilityState !== 'visible')
                return;
            const visit: { cancel?: () => void } = {};
            pending = visit;
            router.reload({
                only: ['location'],
                preserveState: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    if (!active || pending !== visit) token.cancel();
                    else visit.cancel = () => token.cancel();
                },
                onFinish: () => {
                    if (pending === visit) pending = null;
                },
            });
        };
        const visibility = () => {
            if (document.visibilityState !== 'visible') cancel();
        };
        const timer = window.setInterval(refresh, 30_000);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            active = false;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', visibility);
            cancel();
        };
    }, [enabled, accessKey]);
}

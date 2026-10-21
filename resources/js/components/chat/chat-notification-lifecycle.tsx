import { useEffect } from 'react';

/** The worker rechecks the session itself; an old tab cannot assert a different account. */
export function ChatNotificationLifecycle({
    actorId,
}: {
    actorId?: number | string | null;
}) {
    useEffect(() => {
        if (!('serviceWorker' in navigator)) return;
        const worker = navigator.serviceWorker;
        let disposed = false;
        const refresh = async () => {
            try {
                const registration =
                    await worker.getRegistration('/');
                if (!disposed)
                    registration?.active?.postMessage({
                        type: 'chat-account-refresh',
                    });
            } catch {
                // Display checks still fail closed on the worker; do not interrupt the app.
            }
        };
        const visible = () => {
            if (document.visibilityState === 'visible') void refresh();
        };
        void refresh();
        window.addEventListener('focus', refresh);
        window.addEventListener('online', refresh);
        document.addEventListener('visibilitychange', visible);
        worker.addEventListener('controllerchange', refresh);
        return () => {
            disposed = true;
            window.removeEventListener('focus', refresh);
            window.removeEventListener('online', refresh);
            document.removeEventListener('visibilitychange', visible);
            worker.removeEventListener(
                'controllerchange',
                refresh,
            );
        };
    }, [actorId]);
    return null;
}

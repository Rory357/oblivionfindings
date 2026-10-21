import { verifyChatBrowserWorker } from '@/lib/chat-browser-capability';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import {
    destroy as destroyPushSubscription,
    store as storePushSubscription,
} from '@/routes/settings/notifications/push-subscriptions';

type WebPushState = {
    supported: boolean;
    configured: boolean;
    permission: NotificationPermission | 'unsupported';
    enabled: boolean;
    needsUpgrade: boolean;
    processing: boolean;
    error: string | null;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
};

type WebPushPageProps = {
    webpush_public_key?: string | null;
    auth?: { user?: { id: number | string } | null };
};

function csrfToken(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function urlBase64ToArrayBuffer(value: string): ArrayBuffer {
    const padded = `${value}${'='.repeat((4 - (value.length % 4)) % 4)}`;
    const base64 = padded.replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(raw.length) as Uint8Array<ArrayBuffer>;

    [...raw].forEach((char, index) => {
        bytes[index] = char.charCodeAt(0);
    });

    return bytes.buffer;
}

function arrayBufferToBase64Url(buffer: ArrayBuffer | null): string {
    if (!buffer) return '';

    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return window
        .btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/g, '');
}

async function postJson(url: string, method: string, body: object) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
        cache: 'no-store',
        signal: AbortSignal.timeout(10000),
    });

    if (!response.ok) {
        if ([401, 409, 419].includes(response.status)) {
            throw new Error(
                'Your sign-in or browser subscription has changed. Refresh this page.',
            );
        }
        throw new Error('Push subscription could not be saved.');
    }

    return response.status === 204 ? null : response.json();
}

function devicePayload(subscription: PushSubscription, actorId: string) {
    return {
        actor_id: actorId,
        provider: 'webpush',
        token: subscription.endpoint,
        keys: {
            p256dh: arrayBufferToBase64Url(subscription.getKey('p256dh')),
            auth: arrayBufferToBase64Url(subscription.getKey('auth')),
        },
    };
}

async function activeRegistration(registration: ServiceWorkerRegistration) {
    if (registration.active?.state === 'activated') return registration;
    const worker =
        registration.installing ?? registration.waiting ?? registration.active;
    if (!worker)
        throw new Error(
            'Browser notifications are not ready. Please try again.',
        );
    await new Promise<void>((resolve, reject) => {
        const finish = (error?: Error) => {
            clearTimeout(timer);
            worker.removeEventListener('statechange', check);
            if (error) reject(error);
            else resolve();
        };
        const check = () => {
            if (worker.state === 'activated') finish();
            else if (worker.state === 'redundant')
                finish(
                    new Error(
                        'Browser notifications could not start. Please try again.',
                    ),
                );
        };
        const timer = setTimeout(
            () =>
                finish(
                    new Error(
                        'Browser notifications are taking too long to start. Please try again.',
                    ),
                ),
            10000,
        );
        worker.addEventListener('statechange', check);
        check();
    });
    return registration;
}

export function useWebPush(): WebPushState {
    const props = usePage<WebPushPageProps>().props;
    const publicKey = props.webpush_public_key ?? null;
    const actorId =
        props.auth?.user?.id == null ? null : String(props.auth.user.id);
    const supported = useMemo(
        () =>
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window,
        [],
    );
    const [permission, setPermission] = useState<
        NotificationPermission | 'unsupported'
    >(supported ? Notification.permission : 'unsupported');
    const [enabled, setEnabled] = useState(false);
    const [needsUpgrade, setNeedsUpgrade] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const generation = useRef(0);
    const busy = useRef(false);
    const invalidatePending = useCallback(() => {
        generation.current++;
    }, []);

    const configured = Boolean(publicKey);

    useEffect(() => {
        setEnabled(false);
        setNeedsUpgrade(false);
        setProcessing(false);
        setError(null);
        busy.current = false;
        let disposed = false;
        const refresh = async () => {
            if (!supported || !actorId || busy.current || disposed) return;
            const attempt = ++generation.current;
            const current = () => !disposed && attempt === generation.current;
            setEnabled(false);
            setNeedsUpgrade(false);
            setProcessing(true);
            setPermission(Notification.permission);
            try {
                // Reading status must never register, request permission or transfer ownership.
                const registration =
                    await navigator.serviceWorker.getRegistration('/');
                const subscription =
                    await registration?.pushManager.getSubscription();
                if (!current()) return;
                if (subscription && Notification.permission === 'granted') {
                    const status = await postJson(
                        `${storePushSubscription.url()}/status`,
                        'POST',
                        devicePayload(subscription, actorId),
                    );
                    if (current()) {
                        setEnabled(status?.enabled === true);
                        setNeedsUpgrade(
                            status?.enabled === true &&
                                status?.chat_ready === false,
                        );
                    }
                }
                if (current()) setError(null);
            } catch {
                if (current())
                    setError(
                        'Could not check notifications for this account. Refresh this page to try again.',
                    );
            } finally {
                if (current()) setProcessing(false);
            }
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible') void refresh();
        };
        void refresh();
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            disposed = true;
            invalidatePending();
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [supported, actorId, invalidatePending]);

    const subscribe = useCallback(async () => {
        if (!supported || !publicKey || !actorId || busy.current) return;

        busy.current = true;
        const attempt = ++generation.current;
        const current = () => attempt === generation.current;

        setProcessing(true);
        setError(null);

        try {
            const nextPermission =
                Notification.permission === 'granted'
                    ? 'granted'
                    : await Notification.requestPermission();
            if (!current()) return;
            setPermission(nextPermission);

            if (nextPermission !== 'granted') {
                throw new Error('Browser notifications are not allowed.');
            }

            const registration = await activeRegistration(
                await navigator.serviceWorker.register('/sw.js'),
            );
            if (!current()) return;
            const chatProtocolVersion =
                await verifyChatBrowserWorker(registration);
            if (!current()) return;
            const existing = await registration.pushManager.getSubscription();
            if (!current()) return;
            const subscription =
                existing ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToArrayBuffer(publicKey),
                }));
            if (!current()) return;

            await postJson(storePushSubscription.url(), 'POST', {
                ...devicePayload(subscription, actorId),
                platform: 'web',
                chat_protocol_version: chatProtocolVersion,
            });

            if (current()) {
                setEnabled(true);
                setNeedsUpgrade(false);
            }
        } catch (exception) {
            if (current())
                setError(
                    exception instanceof Error
                        ? exception.message
                        : 'Push subscription failed.',
                );
        } finally {
            if (current()) {
                busy.current = false;
                setProcessing(false);
            }
        }
    }, [publicKey, supported, actorId]);

    const unsubscribe = useCallback(async () => {
        if (!supported || !actorId || busy.current) return;

        busy.current = true;
        const attempt = ++generation.current;
        const current = () => attempt === generation.current;

        setProcessing(true);
        setError(null);

        try {
            const registration =
                await navigator.serviceWorker.getRegistration('/');
            const subscription =
                await registration?.pushManager.getSubscription();
            if (!current()) return;

            if (subscription) {
                await postJson(
                    destroyPushSubscription.url(),
                    'DELETE',
                    devicePayload(subscription, actorId),
                );
                // Disable only this account's server binding. Removing the shared
                // browser subscription could destroy a newer binding in another tab.
            }

            if (current()) {
                setEnabled(false);
                setNeedsUpgrade(false);
            }
        } catch (exception) {
            if (current())
                setError(
                    exception instanceof Error
                        ? exception.message
                        : 'Push subscription could not be disabled.',
                );
        } finally {
            if (current()) {
                busy.current = false;
                setProcessing(false);
            }
        }
    }, [supported, actorId]);

    return {
        supported,
        configured,
        permission,
        enabled,
        needsUpgrade,
        processing,
        error,
        subscribe,
        unsubscribe,
    };
}

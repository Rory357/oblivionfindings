import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import {
    destroy as destroyPushSubscription,
    store as storePushSubscription,
} from '@/routes/settings/notifications/push-subscriptions';

type WebPushState = {
    supported: boolean;
    configured: boolean;
    permission: NotificationPermission | 'unsupported';
    enabled: boolean;
    processing: boolean;
    error: string | null;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
};

type WebPushPageProps = {
    webpush_public_key?: string | null;
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
    });

    if (!response.ok) {
        throw new Error('Push subscription could not be saved.');
    }
}

export function useWebPush(): WebPushState {
    const publicKey =
        usePage<WebPushPageProps>().props.webpush_public_key ?? null;
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
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const configured = Boolean(publicKey);

    useEffect(() => {
        if (!supported) return;

        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => setEnabled(Boolean(subscription)))
            .catch(() => setEnabled(false));
    }, [supported]);

    const subscribe = useCallback(async () => {
        if (!supported || !publicKey) return;

        setProcessing(true);
        setError(null);

        try {
            const nextPermission =
                Notification.permission === 'granted'
                    ? 'granted'
                    : await Notification.requestPermission();
            setPermission(nextPermission);

            if (nextPermission !== 'granted') {
                throw new Error('Browser notifications are not allowed.');
            }

            const registration =
                await navigator.serviceWorker.register('/sw.js');
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToArrayBuffer(publicKey),
                }));

            const key = subscription.getKey('p256dh');
            const auth = subscription.getKey('auth');

            await postJson(storePushSubscription.url(), 'POST', {
                provider: 'webpush',
                token: subscription.endpoint,
                keys: {
                    p256dh: arrayBufferToBase64Url(key),
                    auth: arrayBufferToBase64Url(auth),
                },
                platform: 'web',
            });

            setEnabled(true);
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Push subscription failed.',
            );
        } finally {
            setProcessing(false);
        }
    }, [publicKey, supported]);

    const unsubscribe = useCallback(async () => {
        if (!supported) return;

        setProcessing(true);
        setError(null);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription) {
                await postJson(destroyPushSubscription.url(), 'DELETE', {
                    provider: 'webpush',
                    token: subscription.endpoint,
                });
                await subscription.unsubscribe();
            }

            setEnabled(false);
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Push subscription could not be disabled.',
            );
        } finally {
            setProcessing(false);
        }
    }, [supported]);

    return {
        supported,
        configured,
        permission,
        enabled,
        processing,
        error,
        subscribe,
        unsubscribe,
    };
}

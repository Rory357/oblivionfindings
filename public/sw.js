// v4 (eMAR audit Lane A4, EA-007): medication data is never kept on the
// device. Earlier versions cached every page and every /emar GET (MAR pages,
// controlled-register PDFs, CSV exports) where anyone using the browser
// could read them after sign-out. Activating v4 deletes those caches.
const CACHE_NAME = 'of-app-shell-v4';

try {
    importScripts('/chat-sound-receipts.js');
} catch {
    /* Native notification tags remain available when storage coordination cannot load. */
}

// The only pages kept for offline use. None of them shows medication data.
const OFFLINE_URLS = [
    '/fleet-assets/mobile/dashboard',
    '/fleet-assets/daily-check',
];

// Never cached, whatever else changes: medication pages and APIs, exports
// and downloads.
const NEVER_CACHE_PATH =
    /^\/(?:emar|meds|medication|medications|api\/medications)(?:\/|$|-)/i;
const EXPORT_PATH =
    /(?:^|\/)(?:export|exports|pdf|download|downloads)(?:\/|$)|\.(?:pdf|csv|xlsx?)$/i;

function isPrivatePath(url) {
    return (
        NEVER_CACHE_PATH.test(url.pathname) ||
        EXPORT_PATH.test(url.pathname) ||
        url.searchParams.has('export') ||
        url.searchParams.has('download')
    );
}

/** An allowed offline page: same origin, listed above, never a medication path. */
function isOfflinePage(request) {
    if (request.mode !== 'navigate' || request.method !== 'GET') {
        return false;
    }

    const url = new URL(request.url);

    return (
        url.origin === self.location.origin &&
        !isPrivatePath(url) &&
        OFFLINE_URLS.includes(url.pathname)
    );
}

/** A file or no-store answer is never cached, even on an allowed page. */
function isCacheableResponse(response) {
    if (!response || !response.ok || response.type !== 'basic') {
        return false;
    }

    const type = (response.headers.get('Content-Type') || '').toLowerCase();
    const disposition = (
        response.headers.get('Content-Disposition') || ''
    ).toLowerCase();
    const control = (response.headers.get('Cache-Control') || '').toLowerCase();

    return (
        type.includes('text/html') &&
        !disposition.includes('attachment') &&
        !control.includes('no-store')
    );
}

async function addShellUrlsToCache() {
    const cache = await caches.open(CACHE_NAME);

    await Promise.all(
        OFFLINE_URLS.map(async (url) => {
            try {
                const response = await fetch(
                    new Request(url, { cache: 'reload' }),
                );
                if (isCacheableResponse(response)) {
                    await cache.put(url, response);
                }
            } catch {
                // Ignore individual shell cache failures so one protected route
                // does not block service worker installation.
            }
        }),
    );
}

async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);

        if (isCacheableResponse(response)) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(addShellUrlsToCache());
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(reconcileChatNotifications());
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE_NAME)
                        .map((key) => caches.delete(key)),
                ),
            ),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (isChatAuthenticationChange(event.request)) {
        chatDisplayGeneration++;
        chatAuthenticationChanges++;
        event.waitUntil(closeChatNotifications());
        // Forward the original request unchanged. Cleanup failure must never block sign-out.
        event.respondWith(
            fetch(event.request).finally(() => {
                chatAuthenticationChanges--;
                chatDisplayGeneration++;
            }),
        );
        return;
    }
    // Everything else, including every medication page, API, export and
    // PDF, goes straight to the network and is never stored here.
    if (isOfflinePage(event.request)) {
        event.respondWith(networkFirst(event.request));
    }
});

// The app asks for a purge when a worker signs out or another signs in.
self.addEventListener('message', (event) => {
    if (
        event.data?.type === 'chat-worker-capability' &&
        isSameOriginClient(event.source) &&
        typeof event.data.nonce === 'string' &&
        event.data.nonce.length <= 100
    ) {
        event.ports?.[0]?.postMessage({
            type: 'chat-worker-ready',
            nonce: event.data.nonce,
            version: 2,
        });
    }
    if (
        event.data?.type === 'chat-account-refresh' &&
        isSameOriginClient(event.source)
    ) {
        event.waitUntil(reconcileChatNotifications());
    }
    if (event.data && event.data.type === 'purge-caches') {
        event.waitUntil(
            caches
                .keys()
                .then((keys) =>
                    Promise.all(keys.map((key) => caches.delete(key))),
                ),
        );
    }
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = {
            title: 'Notification',
            body: event.data ? event.data.text() : '',
        };
    }

    const title = payload.title || 'Oblivion Findings';
    if (payload.data?.type === 'chat') {
        event.waitUntil(showCurrentChatNotification(payload.data));
        return;
    }
    const options = {
        body: payload.body || payload.message || '',
        data: payload.data || {},
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        tag: payload.data?.type || 'oblivion-findings',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const chatId = chatNotificationId(event.notification.data);
    const targetUrl = resolveNotificationTargetUrl(
        chatId ? `/chat/notifications/${chatId}` : event.notification.data?.url,
    );

    event.waitUntil(
        clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                if (chatId) {
                    // Preserve unfinished work in an existing Oblivion tab. The opaque link rechecks the signed-in user's access.
                    const matching = clientList.find(
                        (client) =>
                            client.url === targetUrl && 'focus' in client,
                    );
                    return matching
                        ? matching.focus()
                        : clients.openWindow(targetUrl);
                }
                for (const client of clientList) {
                    if (
                        'focus' in client &&
                        new URL(client.url).origin === self.location.origin
                    ) {
                        if ('navigate' in client && client.url !== targetUrl) {
                            return client
                                .navigate(targetUrl)
                                .then((targetClient) =>
                                    (targetClient || client).focus(),
                                );
                        }

                        return client.focus();
                    }
                }

                return clients.openWindow(targetUrl);
            }),
    );
});

function chatNotificationId(data) {
    const id = data?.notification_id;
    return data?.type === 'chat' &&
        typeof id === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            id,
        )
        ? id.toLowerCase()
        : null;
}

// In-memory generations only invalidate work already running in this worker.
// The authenticated server check remains the authority after worker restarts.
let chatDisplayGeneration = 0;
let chatAuthenticationChanges = 0;

function isSameOriginClient(client) {
    try {
        return new URL(client?.url).origin === self.location.origin;
    } catch {
        return false;
    }
}

function isChatAuthenticationChange(request) {
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return false;
    if (request.method === 'POST') {
        return [
            '/logout',
            '/login',
            '/two-factor-challenge',
            '/register',
        ].includes(url.pathname);
    }
    return (
        request.method === 'GET' &&
        (/^(?:\/portal)?\/auth\/(?:google|microsoft)\/callback$/.test(
            url.pathname,
        ) ||
            /^\/portal\/login\/link\/[^/]+$/.test(url.pathname))
    );
}

async function currentChatPreview(data) {
    const id = chatNotificationId(data);
    const binding = data?.device_binding;
    if (!id || typeof binding !== 'string' || !/^[a-f0-9]{64}$/.test(binding))
        return null;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch(
            `/chat/notifications/${id}/display?binding=${binding}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (
            !response.ok ||
            response.redirected ||
            !response.headers.get('Content-Type')?.includes('application/json')
        )
            return null;
        const preview = await response.json();
        if (
            chatNotificationId(preview.data) !== id ||
            preview.data?.device_binding !== binding ||
            typeof preview.title !== 'string' ||
            typeof preview.body !== 'string'
        )
            return null;
        return {
            title: preview.title,
            body: preview.body,
            actor_id: preview.actor_id,
            conversation_id: preview.conversation_id,
            data: {
                type: 'chat',
                notification_id: id,
                device_binding: binding,
                silent: preview.data.silent === true,
            },
        };
    } catch {
        // Never show an old account's provider payload when offline, signed out or uncertain.
        return null;
    } finally {
        clearTimeout(timer);
    }
}

async function showCurrentChatNotification(data) {
    if (chatAuthenticationChanges) return;
    const generation = chatDisplayGeneration;
    const preview = await currentChatPreview(data);
    if (
        !preview ||
        generation !== chatDisplayGeneration ||
        chatAuthenticationChanges
    )
        return;
    const tag = `chat-${preview.data.notification_id}`;
    const context =
        Number.isSafeInteger(preview.actor_id) &&
        Number.isSafeInteger(preview.conversation_id)
            ? await globalThis.OblivionChatSoundReceipts?.context?.(
                  preview.actor_id,
              )
            : null;
    const viewing = Boolean(
        context?.active ||
        context?.conversations.includes(preview.conversation_id),
    );
    const receipt = preview.data.silent
        ? false
        : await globalThis.OblivionChatSoundReceipts?.claim([
              preview.data.notification_id,
          ]);
    if (generation !== chatDisplayGeneration || chatAuthenticationChanges)
        return;
    await self.registration.showNotification(preview.title, {
        body: preview.body,
        data: preview.data,
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        tag,
        renotify: false,
        silent: preview.data.silent || viewing || receipt === false,
    });
    if (generation !== chatDisplayGeneration || chatAuthenticationChanges) {
        // An OS display operation can finish after logout cleanup has already enumerated notices.
        const notices = await self.registration.getNotifications({ tag });
        notices.forEach((notice) => {
            if (notice.data?.device_binding === preview.data.device_binding)
                notice.close();
        });
    }
}

async function closeChatNotifications() {
    try {
        const notices = await self.registration.getNotifications();
        notices
            .filter((notice) => notice.data?.type === 'chat')
            .forEach((notice) => notice.close());
    } catch {
        // Notification cleanup must not prevent authentication or worker activation.
    }
}

async function reconcileChatNotifications() {
    const generation = ++chatDisplayGeneration;
    try {
        const notices = (await self.registration.getNotifications()).filter(
            (notice) => notice.data?.type === 'chat',
        );
        // Bound simultaneous permission checks on browsers retaining many old notifications.
        for (let offset = 0; offset < notices.length; offset += 4) {
            if (generation !== chatDisplayGeneration) return;
            await Promise.all(
                notices.slice(offset, offset + 4).map(async (notice) => {
                    const preview = await currentChatPreview(notice.data);
                    if (generation === chatDisplayGeneration && !preview)
                        notice.close();
                }),
            );
        }
    } catch {
        // Some browsers do not expose displayed OS notifications for cleanup.
    }
}

function resolveNotificationTargetUrl(value) {
    try {
        const url = new URL(value || '/my-day', self.location.origin);
        if (url.origin !== self.location.origin) {
            return new URL('/my-day', self.location.origin).href;
        }

        return url.href;
    } catch {
        return new URL('/my-day', self.location.origin).href;
    }
}

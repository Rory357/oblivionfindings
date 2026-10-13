// v4 (eMAR audit Lane A4, EA-007): medication data is never kept on the
// device. Earlier versions cached every page and every /emar GET (MAR pages,
// controlled-register PDFs, CSV exports) where anyone using the browser
// could read them after sign-out. Activating v4 deletes those caches.
const CACHE_NAME = 'of-app-shell-v4';

// The only pages kept for offline use. None of them shows medication data.
const OFFLINE_URLS = ['/fleet-assets/mobile/dashboard', '/fleet-assets/daily-check'];

// Never cached, whatever else changes: medication pages and APIs, exports
// and downloads.
const NEVER_CACHE_PATH = /^\/(?:emar|meds|medication|medications|api\/medications)(?:\/|$|-)/i;
const EXPORT_PATH = /(?:^|\/)(?:export|exports|pdf|download|downloads)(?:\/|$)|\.(?:pdf|csv|xlsx?)$/i;

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
    const disposition = (response.headers.get('Content-Disposition') || '').toLowerCase();
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
                const response = await fetch(new Request(url, { cache: 'reload' }));
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
    // Everything else, including every medication page, API, export and
    // PDF, goes straight to the network and is never stored here.
    if (isOfflinePage(event.request)) {
        event.respondWith(networkFirst(event.request));
    }
});

// The app asks for a purge when a worker signs out or another signs in.
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'purge-caches') {
        event.waitUntil(
            caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key)))),
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

    const targetUrl = resolveNotificationTargetUrl(
        event.notification.data?.url,
    );

    event.waitUntil(
        clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
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

const CACHE_NAME = 'of-app-shell-v3';
const OFFLINE_URLS = [
    '/emar',
    '/emar/mar',
    '/emar/medications',
    '/emar/stock',
    '/meds/today',
    '/fleet-assets/mobile/dashboard',
    '/fleet-assets/daily-check',
];

function isCacheableNavigation(request) {
    return request.mode === 'navigate';
}

function navigationFallbackFor(request) {
    const url = new URL(request.url);

    if (url.pathname === '/meds/today') {
        return '/meds/today';
    }

    if (url.pathname.startsWith('/emar/rounds/') && url.pathname.endsWith('/guided')) {
        return '/meds/today';
    }

    return '/emar';
}

function isCacheableMedicationGet(request) {
    if (request.method !== 'GET') {
        return false;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return false;
    }

    return (
        url.pathname.startsWith('/emar') ||
        url.pathname.startsWith('/api/medications/')
    );
}

async function addShellUrlsToCache() {
    const cache = await caches.open(CACHE_NAME);

    await Promise.all(
        OFFLINE_URLS.map(async (url) => {
            try {
                await cache.add(new Request(url, { cache: 'reload' }));
            } catch {
                // Ignore individual shell cache failures so one protected route
                // does not block service worker installation.
            }
        }),
    );
}

async function networkFirst(request, fallbackUrl) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached =
            (await cache.match(request)) ||
            (fallbackUrl ? await cache.match(fallbackUrl) : undefined);

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
    if (isCacheableNavigation(event.request)) {
        event.respondWith(networkFirst(event.request, navigationFallbackFor(event.request)));
        return;
    }

    if (isCacheableMedicationGet(event.request)) {
        event.respondWith(networkFirst(event.request));
    }
});

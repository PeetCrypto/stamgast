/**
 * STAMGAST Service Worker
 * Midnight Lounge PWA — Cache Strategy
 *
 * - Cache-first for shell assets (CSS, JS, fonts)
 * - Network-first for API data (wallet, transactions)
 * - Push event placeholder (Phase 5)
 */

const CACHE_VERSION = 'stamgast-shell-v1';

// Shell assets to pre-cache on install
const SHELL_ASSETS = [
    '/css/midnight-lounge.css',
    '/css/components.css',
    '/css/views.css',
    '/js/app.js',
    '/js/wallet.js',
    '/js/qr.js',
    '/js/pos.js',
    '/js/admin.js',
];

// External assets to cache on first fetch (not pre-cached to avoid CORS issues)
const EXTERNAL_CACHE_PATTERNS = [
    /fonts\.googleapis\.com/,
    /fonts\.gstatic\.com/,
];

// API routes that should always be network-first
const API_PREFIX = '/api/';

// ============================================
// INSTALL — Pre-cache shell assets
// ============================================
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => {
                // Cache shell assets individually to avoid one failure blocking all
                return Promise.allSettled(
                    SHELL_ASSETS.map((url) =>
                        cache.add(url).catch((err) => {
                            console.warn('[SW] Failed to cache:', url, err.message);
                        })
                    )
                );
            })
            .then(() => {
                console.log('[SW] Shell assets cached');
                return self.skipWaiting();
            })
    );
});

// ============================================
// ACTIVATE — Clean up old caches
// ============================================
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_VERSION)
                        .map((name) => {
                            console.log('[SW] Removing old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Activated');
                return self.clients.claim();
            })
    );
});

// ============================================
// FETCH — Routing strategy
// ============================================
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip chrome-extension and other non-http(s) requests
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // Strategy 1: Network-first for API calls
    if (url.pathname.startsWith(API_PREFIX)) {
        event.respondWith(networkFirst(request));
        return;
    }

    // Strategy 2: Cache-first for shell assets
    if (isShellAsset(url.pathname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Strategy 3: Stale-while-revalidate for external assets (fonts, etc.)
    if (isExternalAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    // Strategy 4: Network-first with cache fallback for everything else (pages)
    event.respondWith(networkFirst(request));
});

// ============================================
// CACHE STRATEGIES
// ============================================

/**
 * Cache-first: Serve from cache, fall back to network
 * Used for: CSS, JS shell assets
 */
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_VERSION);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        // Offline and not cached — return minimal offline response
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

/**
 * Network-first: Try network, fall back to cache
 * Used for: API calls, HTML pages
 */
async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_VERSION);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }
        // For navigation requests, we could serve a cached page
        if (request.mode === 'navigate') {
            const cached = await caches.match('/');
            if (cached) return cached;
        }
        return new Response(JSON.stringify({ success: false, error: 'Offline' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/**
 * Stale-while-revalidate: Serve from cache, update in background
 * Used for: External fonts, CDN assets
 */
async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);

    const fetchPromise = fetch(request)
        .then((response) => {
            if (response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => cached);

    return cached || fetchPromise;
}

// ============================================
// HELPERS
// ============================================

function isShellAsset(pathname) {
    return (
        pathname.startsWith('/css/') ||
        pathname.startsWith('/js/') ||
        pathname.startsWith('/icons/') ||
        pathname.endsWith('.css') ||
        pathname.endsWith('.js') ||
        pathname.endsWith('.png') ||
        pathname.endsWith('.ico')
    );
}

function isExternalAsset(url) {
    return EXTERNAL_CACHE_PATTERNS.some((pattern) => pattern.test(url.href));
}

// ============================================
// PUSH EVENT (Phase 5 placeholder)
// ============================================
self.addEventListener('push', (event) => {
    if (!event.data) return;

    try {
        const data = event.data.json();
        const title = data.title || 'STAMGAST';
        const options = {
            body: data.body || '',
            icon: '/icons/favicon.png',
            badge: '/icons/favicon.png',
            vibrate: [100, 50, 100],
            data: {
                url: data.url || '/',
            },
        };

        event.waitUntil(self.registration.showNotification(title, options));
    } catch (err) {
        console.warn('[SW] Push parse error:', err);
    }
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                // Focus existing window if open
                for (const client of clients) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        return client.focus();
                    }
                }
                // Open new window
                return self.clients.openWindow(url);
            })
    );
});

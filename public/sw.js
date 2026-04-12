/**
 * sw.js – Service Worker für MDKursTracker
 *
 * Strategie:
 *  - Install:  App-Shell (HTML, CSS, JS, Manifest, Icons) vorab cachen
 *  - Fetch:    App-Shell-Ressourcen → Cache First
 *              API-Aufrufe (/api/, /admin-api/) → Network First, kein Cache
 *              Navigationsanfragen → App-Shell aus Cache (SPA-Fallback)
 */

const CACHE_VERSION = 'v12';
const CACHE_NAME = `mdkurstracker-shell-${CACHE_VERSION}`;

/** Ressourcen, die beim Install gecacht werden */
const APP_SHELL = [
    '/',
    '/manifest.json',
    '/css/app.css',
    '/js/app.js',
    '/js/api.js',
    '/js/utils/format.js',
    '/js/utils/geolocation.js',
    '/js/utils/lines.js',
    '/js/views/nearby.js',
    '/js/views/departures.js',
    '/js/views/capture.js',
    '/js/views/history.js',
    '/js/views/info.js',
    '/icons/icon-192.svg',
    '/icons/icon-512.svg',
];

// =============================================================================
// Install-Event: App-Shell vorab cachen
// =============================================================================

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting())   // Sofort aktivieren ohne auf Tab-Schließen zu warten
    );
});

// =============================================================================
// Activate-Event: Alte Caches löschen
// =============================================================================

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => key.startsWith('mdkurstracker-') && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            ))
            .then(() => self.clients.claim())  // Sofort alle Tabs übernehmen
            // Alle offenen Fenster zur aktuellen URL navigieren → erzwingt
            // Reload mit neuem Cache, ohne Kooperation von app.js.
            // Löst das Bootstrap-Problem bei bereits installierten PWAs.
            .then(() => self.clients.matchAll({ type: 'window' }))
            .then(clients => Promise.all(
                clients.map(client => client.navigate(client.url))
            ))
    );
});

// =============================================================================
// Fetch-Event: Anfragen abfangen
// =============================================================================

self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Nur GET-Anfragen derselben Origin behandeln
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // API-Aufrufe: immer Network First, kein Cache (Echtzeitdaten)
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin-api/')) {
        event.respondWith(fetch(request));
        return;
    }

    // Navigationsanfragen (HTML): App-Shell aus Cache ausliefern (SPA-Fallback)
    // Ausnahme: /admin/ und Unterpfade → immer vom Netzwerk laden (eigene HTML-Shell)
    if (request.mode === 'navigate') {
        if (url.pathname.startsWith('/admin/') || url.pathname === '/admin') {
            return; // SW nicht einmischen → Browser fragt direkt den Server
        }
        event.respondWith(
            caches.match('/')
                .then(cached => cached ?? fetch(request))
                .catch(() => caches.match('/'))
        );
        return;
    }

    // App-Shell-Ressourcen (CSS, JS, Icons): Cache First, dann Network
    event.respondWith(
        caches.match(request)
            .then(cached => {
                if (cached) return cached;
                // Nicht im Cache → Netzwerk und dabei cachen
                return fetch(request).then(networkResponse => {
                    if (networkResponse.ok) {
                        const clone = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    }
                    return networkResponse;
                });
            })
            .catch(() => caches.match('/'))  // Offline-Fallback: App-Shell
    );
});

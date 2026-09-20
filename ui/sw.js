// Bump CACHE_NAME whenever shipping CSS/chrome so PWA clients drop stale assets.
// Pages load style.css?v=N; the SW still pre-caches /style.css - both must stay in sync.
const CACHE_NAME = 'keeplore-v6';
const STATIC_ASSETS = [
  '/style.css',
  '/manifest.json',
  '/assets/icon-192x192.png',
  '/assets/icon-512x512.png',
  '/assets/copy.png',
  '/shared/js/api-client.js',
  '/shared/js/search-component.js',
  '/shared/js/filter-utils.js',
  '/shared/js/theme.js'
];

const OFFLINE_PAGE = '/offline.html';

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      cache.addAll([...STATIC_ASSETS, OFFLINE_PAGE])
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Navigation requests: network first, offline fallback
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match(OFFLINE_PAGE))
    );
    return;
  }

  // API requests: network only (don't cache dynamic data)
  if (request.url.includes('/api/') || request.url.includes('api.keeplore')) {
    event.respondWith(fetch(request));
    return;
  }

  // Static assets: stale-while-revalidate
  event.respondWith(
    caches.match(request).then(cached => {
      const fetchPromise = fetch(request).then(response => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
        }
        return response;
      }).catch(() => cached);

      return cached || fetchPromise;
    })
  );
});

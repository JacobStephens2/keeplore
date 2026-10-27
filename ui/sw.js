// Bump CACHE_NAME whenever shipping CSS/chrome so PWA clients drop stale assets.
// Pages load style.css?v=N; the SW still pre-caches /style.css - both must stay in sync.
// v28 drops responses stored before no-store was refused.
const CACHE_NAME = 'keeplore-v28';
const STATIC_ASSETS = [
  '/style.css',
  '/manifest.json',
  '/assets/icon-192x192.png',
  '/assets/icon-512x512.png',
  '/assets/copy.png',
  '/shared/js/api-client.js',
  '/shared/js/filter-utils.js',
  '/shared/js/theme.js',
  '/shared/js/page-shortcuts.js'
];

const OFFLINE_PAGE = '/offline.html';

function isNoStore(response) {
  if (!response || !response.headers) return false;
  const header = response.headers.get('Cache-Control') || '';
  return /\bno-store\b/i.test(header);
}

function isLogoutRequest(request) {
  let url;
  try {
    url = new URL(request.url);
  } catch {
    return false;
  }
  const path = url.pathname.replace(/\/+$/, '').toLowerCase();
  if (path === '/logout' || path === '/logout.php') return true;
  if (path === '/login' || path === '/login.php') {
    return (url.searchParams.get('action') || '').toLowerCase() === 'logout';
  }
  return false;
}

// Match, but drop a stored no-store response so it cannot be served or read back.
function matchCacheable(request) {
  return caches.match(request).then(cached => {
    if (!cached || !isNoStore(cached)) return cached;
    return caches.open(CACHE_NAME).then(cache => cache.delete(request)).then(() => undefined);
  });
}

function storeIfCacheable(request, response) {
  if (!response || !response.ok || isNoStore(response)) return Promise.resolve();
  const clone = response.clone();
  return caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
}

self.addEventListener('install', event => {
  const urls = [...STATIC_ASSETS, OFFLINE_PAGE];
  event.waitUntil(
    Promise.all(urls.map(url =>
      fetch(url).then(response => {
        if (!response.ok) throw new TypeError('Request failed');
        return { url, response };
      })
    )).then(fetched =>
      Promise.all(fetched.map(({ url, response }) => storeIfCacheable(url, response)))
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

  // Logout clears the cache this worker is using, including anything stored
  // before no-store was refused. The navigation response itself is not cached.
  if (isLogoutRequest(request)) {
    event.respondWith(
      fetch(request).then(response =>
        caches.delete(CACHE_NAME).then(() => response)
      ).catch(() =>
        matchCacheable(OFFLINE_PAGE).then(offline =>
          caches.delete(CACHE_NAME).then(() => offline)
        )
      )
    );
    return;
  }

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Navigation requests: network first, offline fallback
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => matchCacheable(OFFLINE_PAGE))
    );
    return;
  }

  // API requests: network only (don't cache dynamic data)
  if (request.url.includes('/api/') || request.url.includes('api.keeplore')) {
    event.respondWith(fetch(request));
    return;
  }

  // Static assets: stale-while-revalidate, never a no-store response
  event.respondWith(
    matchCacheable(request).then(cached => {
      const fetchPromise = fetch(request).then(response => {
        storeIfCacheable(request, response);
        return response;
      }).catch(() => cached);

      return cached || fetchPromise;
    })
  );
});

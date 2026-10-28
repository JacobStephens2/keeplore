// Bump CACHE_NAME whenever shipping CSS/chrome so PWA clients drop stale assets.
// Pages load style.css?v=N; the SW still pre-caches /style.css - both must stay in sync.
// v29 drops responses stored from another origin.
const CACHE_NAME = 'keeplore-v29';
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
const PUBLIC_SHELL = [...STATIC_ASSETS, OFFLINE_PAGE];

function isNoStore(response) {
  if (!response || !response.headers) return false;
  const header = response.headers.get('Cache-Control') || '';
  return /\bno-store\b/i.test(header);
}

function isThisOrigin(url) {
  try {
    return new URL(url, self.location.origin).origin === self.location.origin;
  } catch {
    return false;
  }
}

function isLogoutRequest(request) {
  let url;
  try {
    url = new URL(request.url);
  } catch {
    return false;
  }
  // Item links and outbound navigations use other origins. Only this
  // origin's logout may delete the cache.
  if (url.origin !== self.location.origin) return false;
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

// Install stores nothing unless every public asset loaded. A no-store asset is skipped.
function precachePublicAssets() {
  return Promise.all(PUBLIC_SHELL.map(url =>
    fetch(url).then(response => {
      if (!response || !response.ok) throw new TypeError('Request failed');
      return { url, response };
    })
  )).then(fetched =>
    Promise.all(fetched.map(({ url, response }) => storeIfCacheable(url, response)))
  );
}

// After logout, one asset failing to load should not drop the rest of the shell.
function restorePublicShell() {
  return Promise.all(PUBLIC_SHELL.map(url =>
    fetch(url).then(response => {
      if (!response || !response.ok) return undefined;
      return storeIfCacheable(url, response);
    }).catch(() => undefined)
  ));
}

// A navigation to logout.php is an opaque redirect (status 0). Guest exit is 200.
function logoutReachedServer(response) {
  if (!response) return false;
  if (response.type === 'opaqueredirect') return true;
  return response.status >= 200 && response.status < 400;
}

self.addEventListener('install', event => {
  event.waitUntil(precachePublicAssets());
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

  // A logout the server answered deletes this cache before the navigation
  // continues. The public shell is filled again in the background. A network
  // failure is not a logout: leave the cache alone.
  if (isLogoutRequest(request)) {
    const outcome = fetch(request).then(response => {
      if (!logoutReachedServer(response)) return { response, cleared: false };
      return caches.delete(CACHE_NAME).then(() => ({ response, cleared: true }));
    });
    event.waitUntil(
      outcome.then(({ cleared }) => (cleared ? restorePublicShell() : undefined)).catch(() => undefined)
    );
    event.respondWith(
      outcome.then(({ response }) => response).catch(() => matchCacheable(OFFLINE_PAGE))
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

  // Another host is network only. Staging's API host matches neither check
  // above, and a cached 200 would be replayed after a later 401.
  if (!isThisOrigin(request.url)) {
    event.respondWith(fetch(request).catch(() => undefined));
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

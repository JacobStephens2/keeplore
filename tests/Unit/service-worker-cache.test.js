const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const workerPath = path.join(__dirname, '../../ui/sw.js');
const workerSource = fs.readFileSync(workerPath, 'utf8');
const cacheName = workerSource.match(/const CACHE_NAME = '([^']+)';/)[1];
const ORIGIN = 'https://keeplore.test';

function requestUrl(request) {
  if (typeof request === 'string') return new URL(request, ORIGIN).href;
  return request.url;
}

function loadWorker(fetchImpl) {
  const listeners = {};
  const stores = new Map();
  const pending = new Set();

  function track(promise) {
    const settled = Promise.resolve(promise).then(() => {}, () => {});
    pending.add(settled);
    settled.then(() => pending.delete(settled));
    return promise;
  }

  class MemoryCache {
    constructor() {
      this.entries = new Map();
    }

    put(request, response) {
      return track(Promise.resolve().then(() => {
        this.entries.set(requestUrl(request), response.clone());
      }));
    }

    match(request) {
      return track(Promise.resolve().then(() => {
        const found = this.entries.get(requestUrl(request));
        return found ? found.clone() : undefined;
      }));
    }

    delete(request) {
      return track(Promise.resolve().then(() => this.entries.delete(requestUrl(request))));
    }

    // Browser addAll stores the fetched response, Cache-Control included.
    addAll(requests) {
      return track((async () => {
        const fetched = [];
        for (const request of requests) {
          const response = await fetchImpl(request);
          if (!response || !response.ok) throw new TypeError('Request failed');
          fetched.push([request, response]);
        }
        for (const [request, response] of fetched) {
          this.entries.set(requestUrl(request), response.clone());
        }
      })());
    }
  }

  const caches = {
    open(name) {
      return track(Promise.resolve().then(() => {
        if (!stores.has(name)) stores.set(name, new MemoryCache());
        return stores.get(name);
      }));
    },
    match(request) {
      return track((async () => {
        for (const cache of stores.values()) {
          const found = await cache.match(request);
          if (found) return found;
        }
        return undefined;
      })());
    },
    delete(name) {
      return track(Promise.resolve().then(() => stores.delete(name)));
    },
    keys() {
      return track(Promise.resolve().then(() => [...stores.keys()]));
    },
  };

  const context = vm.createContext({
    self: {
      addEventListener(type, fn) {
        if (!listeners[type]) listeners[type] = [];
        listeners[type].push(fn);
      },
      skipWaiting() {},
      clients: { claim() { return Promise.resolve(); } },
    },
    caches,
    fetch(request) {
      return track(Promise.resolve().then(() => fetchImpl(request)));
    },
    URL,
    Response,
    Request,
    Headers,
    console,
    Promise,
  });
  vm.runInContext(workerSource, context, { filename: 'ui/sw.js' });

  async function idle() {
    for (let turn = 0; turn < 30; turn += 1) {
      await new Promise((resolve) => setImmediate(resolve));
      if (pending.size === 0) return;
    }
    throw new Error('service worker cache work did not settle');
  }

  async function dispatch(type, event) {
    for (const listener of listeners[type] || []) listener(event);
  }

  return {
    caches,
    async install() {
      let done;
      await dispatch('install', {
        waitUntil(promise) { done = promise; },
      });
      if (done) await done;
      await idle();
    },
    async fetch(url, init = {}) {
      const request = { url, method: 'GET', mode: 'cors', ...init };
      let responsePromise;
      await dispatch('fetch', {
        request,
        respondWith(promise) { responsePromise = promise; },
        waitUntil() {},
      });
      const response = responsePromise ? await responsePromise : undefined;
      await idle();
      return response;
    },
  };
}

async function body(response) {
  if (!response) return null;
  return response.text();
}

test('a no-store response is not stored or later served from the service worker cache', async () => {
  const url = ORIGIN + '/artifacts/items-data.php';
  let calls = 0;
  const worker = loadWorker(() => {
    calls += 1;
    if (calls === 1) {
      return new Response('{"items":["private"]}', {
        status: 200,
        headers: { 'Cache-Control': 'private, no-store' },
      });
    }
    return Promise.reject(new Error('offline'));
  });

  const first = await worker.fetch(url);
  assert.equal(await body(first), '{"items":["private"]}');

  const stored = await worker.caches.match(url);
  assert.equal(stored, undefined);

  const second = await worker.fetch(url);
  assert.equal(await body(second), null);
});

test('a stored no-store response is not served when the network has a fresh one', async () => {
  const url = ORIGIN + '/artifacts/bgg-data.php';
  const worker = loadWorker(() => new Response('fresh', {
    status: 200,
    headers: { 'Cache-Control': 'no-store' },
  }));
  const cache = await worker.caches.open(cacheName);
  await cache.put(url, new Response('private-items', {
    status: 200,
    headers: { 'Cache-Control': 'no-store' },
  }));

  const response = await worker.fetch(url);

  assert.equal(await body(response), 'fresh');
  assert.equal(await worker.caches.match(url), undefined);
});

test('install does not precache a no-store asset', async () => {
  const worker = loadWorker((request) => {
    const url = requestUrl(request);
    if (url.endsWith('/style.css')) {
      return new Response('secret-css', {
        status: 200,
        headers: { 'Cache-Control': 'no-store' },
      });
    }
    return new Response('public', {
      status: 200,
      headers: { 'Cache-Control': 'max-age=60' },
    });
  });

  await worker.install();

  assert.equal(await worker.caches.match(ORIGIN + '/style.css'), undefined);
  const manifest = await worker.caches.match(ORIGIN + '/manifest.json');
  assert.equal(await body(manifest), 'public');
});

test('a cacheable asset is still served from the service worker cache when the network fails', async () => {
  const url = ORIGIN + '/style.css';
  let calls = 0;
  const worker = loadWorker(() => {
    calls += 1;
    if (calls === 1) {
      return new Response('body{color:black}', {
        status: 200,
        headers: { 'Cache-Control': 'max-age=3600' },
      });
    }
    return Promise.reject(new Error('offline'));
  });

  const first = await worker.fetch(url);
  assert.equal(await body(first), 'body{color:black}');

  const second = await worker.fetch(url);
  assert.equal(await body(second), 'body{color:black}');
});

test('logout deletes the current service worker cache', async () => {
  const asset = ORIGIN + '/style.css';
  const worker = loadWorker((request) => {
    if (requestUrl(request) === asset) {
      return new Response('body{color:black}', {
        status: 200,
        headers: { 'Cache-Control': 'max-age=3600' },
      });
    }
    return new Response('', { status: 302, headers: { Location: '/login.php' } });
  });

  await worker.fetch(asset);
  assert.deepEqual(await worker.caches.keys(), [cacheName]);

  const response = await worker.fetch(ORIGIN + '/logout', { mode: 'navigate' });
  assert.equal(response.status, 302);
  assert.deepEqual(await worker.caches.keys(), []);
  assert.equal(await worker.caches.match(asset), undefined);
});

test('logout.php and guest exit delete the current service worker cache', async () => {
  for (const logoutUrl of [
    ORIGIN + '/logout.php',
    ORIGIN + '/login.php?action=logout',
    ORIGIN + '/login?action=logout',
  ]) {
    const asset = ORIGIN + '/shared/js/theme.js';
    const worker = loadWorker((request) => {
      if (requestUrl(request) === asset) {
        return new Response('theme', { status: 200, headers: { 'Cache-Control': 'max-age=60' } });
      }
      return new Response('login-form', { status: 200 });
    });

    await worker.fetch(asset);
    const response = await worker.fetch(logoutUrl, { mode: 'navigate' });
    assert.equal(await body(response), 'login-form', logoutUrl);
    assert.deepEqual(await worker.caches.keys(), [], logoutUrl);
  }
});

test('opening the login page does not delete the service worker cache', async () => {
  const asset = ORIGIN + '/style.css';
  const worker = loadWorker((request) => {
    if (requestUrl(request) === asset) {
      return new Response('body{color:black}', {
        status: 200,
        headers: { 'Cache-Control': 'max-age=3600' },
      });
    }
    return new Response('login-form', { status: 200 });
  });

  await worker.fetch(asset);
  const response = await worker.fetch(ORIGIN + '/login.php', { mode: 'navigate' });

  assert.equal(await body(response), 'login-form');
  assert.deepEqual(await worker.caches.keys(), [cacheName]);
});

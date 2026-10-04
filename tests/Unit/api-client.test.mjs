import { test } from 'node:test';
import assert from 'node:assert/strict';

// The client reads the API host from the page and calls fetch; stand in for both.
globalThis.document = { querySelector: () => null };
globalThis.location = { href: '/record/' };
const { default: ApiClient } = await import('../../ui/shared/js/api-client.js');

function answer(status, body) {
  globalThis.fetch = async () => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => body,
  });
}

test('a 401 sends the visitor to log in and never settles', async () => {
  location.href = '/record/';
  answer(401, { authenticated: false, message: 'You have not been authenticated' });

  const settled = await Promise.race([
    ApiClient.getTypes().then(() => 'resolved', () => 'rejected'),
    new Promise((resolve) => setTimeout(() => resolve('pending'), 20)),
  ]);

  assert.equal(settled, 'pending');
  assert.equal(location.href, '/login.php');
});

test('another error status rejects with the body message and stays on the page', async () => {
  location.href = '/record/';
  answer(403, { authenticated: true, message: 'Agent keys permit reads plus the kept toggle only.' });

  await assert.rejects(ApiClient.createUse({}), { message: 'Agent keys permit reads plus the kept toggle only.' });
  assert.equal(location.href, '/record/');
});

test('an ok response resolves with its body', async () => {
  answer(200, { types: [{ id: 1, type: 'board-game' }] });

  assert.deepEqual(await ApiClient.getTypes(), { types: [{ id: 1, type: 'board-game' }] });
});

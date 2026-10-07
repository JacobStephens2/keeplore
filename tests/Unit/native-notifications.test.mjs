import { test } from 'node:test';
import assert from 'node:assert/strict';

let pageLoad = 0;

async function loadNotifications({ readyState = 'complete', initialAction, data, native = true } = {}) {
  const listeners = new Map();
  const scheduled = [];
  globalThis.document = {
    readyState,
    querySelector: () => null,
    addEventListener() {},
  };
  globalThis.window = {
    location: { href: '/index.php' },
    Capacitor: {
      isNativePlatform: () => native,
      Plugins: {
        LocalNotifications: {
          addListener: async (name, listener) => {
            listeners.set(name, listener);
            if (initialAction) queueMicrotask(() => listener(initialAction));
            return { remove() { listeners.delete(name); } };
          },
          checkPermissions: async () => ({ display: 'granted' }),
          getPending: async () => ({ notifications: [] }),
          schedule: async ({ notifications }) => { scheduled.push(...notifications); },
        },
      },
    },
  };
  globalThis.fetch = async () => data
    ? { status: 200, ok: true, json: async () => data }
    : { status: 401 };
  await import(`../../ui/native-notifications.js?test=${++pageLoad}`);
  await new Promise((resolve) => setImmediate(resolve));
  return {
    scheduled,
    action(action) {
      listeners.get('localNotificationActionPerformed')?.(action);
    },
    tap(notification) {
      listeners.get('localNotificationActionPerformed')?.({ actionId: 'tap', notification });
    },
  };
}

test('tapping an overdue notification opens Edit Item for its item in the app', async () => {
  const app = await loadNotifications();

  app.tap({ id: 423, title: 'Overdue', extra: { item_id: 42 } });

  assert.equal(window.location.href, '/artifacts/edit.php?id=42');
});

test('item metadata takes precedence over the legacy notification ID', async () => {
  const app = await loadNotifications();

  app.tap({ id: 733, extra: { item_id: '42' } });

  assert.equal(window.location.href, '/artifacts/edit.php?id=42');
});

test('legacy due-soon and due-today taps also open their item', async () => {
  const app = await loadNotifications();

  for (const id of [421, 422]) {
    app.tap({ id });
    assert.equal(window.location.href, '/artifacts/edit.php?id=42');
  }
});

test('dismissals and malformed notification destinations leave the current page open', async () => {
  const app = await loadNotifications();

  for (const action of [
    null,
    {},
    { actionId: 'dismiss', notification: { id: 423, extra: { item_id: 42 } } },
    { actionId: 'tap' },
    ...[{}, { id: 0 }, { id: -423 }, { id: 424 }, { id: 423.5 }, { id: 'invalid' },
      ...[0, -1, 4.2, '', true, '42&other=1', 'https://example.com', Number.MAX_SAFE_INTEGER + 1]
        .map((item_id) => ({ id: 423, extra: { item_id } }))]
      .map((notification) => ({ actionId: 'tap', notification })),
  ]) {
    app.action(action);
    assert.equal(window.location.href, '/index.php', JSON.stringify(action));
  }
});

test('opening the page in a browser does not handle native notification taps', async () => {
  const app = await loadNotifications({ native: false });

  app.tap({ id: 423, extra: { item_id: 42 } });

  assert.equal(window.location.href, '/index.php');
  assert.deepEqual(app.scheduled, []);
});

test('turning off future reminders still lets a delivered notification open its item', async () => {
  const app = await loadNotifications({ data: { notification_prefs: { enabled: false } } });

  app.tap({ id: 423, extra: { item_id: 42 } });

  assert.equal(window.location.href, '/artifacts/edit.php?id=42');
  assert.deepEqual(app.scheduled, []);
});

test('every scheduled reminder retains its item for opening Edit Item', async (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date('2030-01-01T00:00:00').getTime() });
  const app = await loadNotifications({
    data: {
      items: [
        { id: 42, title: 'Catan', use_by_date: '2030-01-10', status: 'upcoming' },
        { id: 73, title: 'Azul', use_by_date: '2029-12-01', status: 'past_due' },
      ],
    },
  });

  assert.deepEqual(app.scheduled.map(({ id, extra }) => ({ id, extra })), [
    { id: 421, extra: { item_id: 42 } },
    { id: 422, extra: { item_id: 42 } },
    { id: 423, extra: { item_id: 42 } },
    { id: 733, extra: { item_id: 73 } },
  ]);
  for (const notification of app.scheduled) {
    app.tap(notification);
    assert.equal(window.location.href, notification.id === 733
      ? '/artifacts/edit.php?id=73'
      : '/artifacts/edit.php?id=42');
  }
});

test('a retained cold-start tap opens the item before the page is ready or the session fetch succeeds', async () => {
  await loadNotifications({
    readyState: 'loading',
    initialAction: { actionId: 'tap', notification: { id: 423, extra: { item_id: 42 } } },
  });

  assert.equal(window.location.href, '/artifacts/edit.php?id=42');
});

test('an overdue notification scheduled before item metadata was added still opens its item', async () => {
  const app = await loadNotifications();

  app.tap({ id: 733, title: 'Overdue' });

  assert.equal(window.location.href, '/artifacts/edit.php?id=73');
});

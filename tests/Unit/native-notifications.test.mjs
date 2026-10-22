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

test('turning off native notifications cancels pending ones and schedules nothing', async () => {
  const app = await loadNotifications({
    data: {
      notification_prefs: { enabled: false, hour: 9 },
      reminders: [
        { id: 421, title: 'Due soon', body: 'Catan is due 2030-01-10', day: '2030-01-07', item_id: 42 },
      ],
    },
  });

  app.tap({ id: 423, extra: { item_id: 42 } });

  assert.equal(window.location.href, '/artifacts/edit.php?id=42');
  assert.deepEqual(app.scheduled, []);
});

test('a dated reminder is scheduled only when that day at the device-local hour is still ahead', async (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date(2030, 0, 1, 0, 0, 0, 0).getTime() });
  const app = await loadNotifications({
    data: {
      notification_prefs: { enabled: true, hour: 9 },
      reminders: [
        { id: 421, title: 'Due soon', body: 'Catan is due 2030-01-10', day: '2030-01-07', item_id: 42 },
        { id: 422, title: 'Due today', body: 'Catan is due today', day: '2030-01-10', item_id: 42 },
        { id: 423, title: 'Overdue', body: 'Catan is overdue', day: '2030-01-11', item_id: 42 },
        { id: 731, title: 'Due soon', body: 'Azul is due 2029-12-01', day: '2029-11-28', item_id: 73 },
        { id: 732, title: 'Due today', body: 'Azul is due today', day: '2029-12-01', item_id: 73 },
      ],
    },
  });

  assert.deepEqual(
    app.scheduled.map(({ id, title, body, extra, schedule }) => ({ id, title, body, extra, at: schedule.at, allowWhileIdle: schedule.allowWhileIdle })),
    [
      { id: 421, title: 'Due soon', body: 'Catan is due 2030-01-10', extra: { item_id: 42 }, at: new Date(2030, 0, 7, 9, 0, 0, 0), allowWhileIdle: true },
      { id: 422, title: 'Due today', body: 'Catan is due today', extra: { item_id: 42 }, at: new Date(2030, 0, 10, 9, 0, 0, 0), allowWhileIdle: true },
      { id: 423, title: 'Overdue', body: 'Catan is overdue', extra: { item_id: 42 }, at: new Date(2030, 0, 11, 9, 0, 0, 0), allowWhileIdle: true },
    ],
  );
  for (const notification of app.scheduled) {
    app.tap(notification);
    assert.equal(window.location.href, '/artifacts/edit.php?id=42');
  }
});

test('a catch-up is scheduled at the next device-local hour when the dated overdue reminder has passed', async (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date(2030, 0, 1, 0, 0, 0, 0).getTime() });
  const app = await loadNotifications({
    data: {
      notification_prefs: { enabled: true, hour: 9 },
      reminders: [
        { id: 733, title: 'Overdue', body: 'Azul is overdue', day: '2029-12-02', item_id: 73 },
        { id: 733, title: 'Overdue', body: 'Azul is overdue (due 2029-12-01)', day: null, item_id: 73 },
      ],
    },
  });

  assert.deepEqual(
    app.scheduled.map(({ id, title, body, extra, schedule }) => ({ id, title, body, extra, at: schedule.at, allowWhileIdle: schedule.allowWhileIdle })),
    [
      { id: 733, title: 'Overdue', body: 'Azul is overdue (due 2029-12-01)', extra: { item_id: 73 }, at: new Date(2030, 0, 1, 9, 0, 0, 0), allowWhileIdle: true },
    ],
  );
  app.tap(app.scheduled[0]);
  assert.equal(window.location.href, '/artifacts/edit.php?id=73');
});

test('the same notification id is not scheduled twice: a dated overdue reminder still ahead wins', async (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date(2030, 0, 1, 0, 0, 0, 0).getTime() });
  const app = await loadNotifications({
    data: {
      notification_prefs: { enabled: true, hour: 9 },
      reminders: [
        { id: 423, title: 'Overdue', body: 'Catan is overdue', day: '2030-01-11', item_id: 42 },
        { id: 423, title: 'Overdue', body: 'Catan is overdue (due 2030-01-10)', day: null, item_id: 42 },
      ],
    },
  });

  assert.deepEqual(
    app.scheduled.map(({ id, body, schedule }) => ({ id, body, at: schedule.at })),
    [{ id: 423, body: 'Catan is overdue', at: new Date(2030, 0, 11, 9, 0, 0, 0) }],
  );
});

test('a catch-up after the device-local hour waits until that hour tomorrow', async (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date(2030, 0, 1, 10, 0, 0, 0).getTime() });
  const app = await loadNotifications({
    data: {
      notification_prefs: { enabled: true, hour: 9 },
      reminders: [
        { id: 733, title: 'Overdue', body: 'Azul is overdue (due 2029-12-01)', day: null, item_id: 73 },
      ],
    },
  });

  assert.deepEqual(
    app.scheduled.map(({ schedule }) => schedule.at),
    [new Date(2030, 0, 2, 9, 0, 0, 0)],
  );
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

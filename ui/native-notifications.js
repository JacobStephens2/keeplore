import { API_BASE } from './uses/modules/publicEnvironmentVariables.js';

(function () {
  if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
    return;
  }

  var LocalNotifications = window.Capacitor.Plugins && window.Capacitor.Plugins.LocalNotifications;
  if (!LocalNotifications) return;

  var API_URL = API_BASE + '/upcoming-interactions.php';

  LocalNotifications.addListener('localNotificationActionPerformed', function (action) {
    if (!action || action.actionId !== 'tap' || !action.notification) return;
    var itemId = action.notification.extra && action.notification.extra.item_id;
    // Notifications already on the device use item ID * 10 + 1/2/3.
    if (itemId == null) {
      var notificationId = Number(action.notification.id);
      var kind = notificationId % 10;
      if (!Number.isSafeInteger(notificationId) || kind < 1 || kind > 3) return;
      itemId = Math.floor(notificationId / 10);
    }
    if (!/^[1-9]\d*$/.test(String(itemId)) || !Number.isSafeInteger(Number(itemId))) return;
    window.location.href = '/artifacts/edit.php?id=' + itemId;
  }).catch(function (err) {
    console.warn('Notification tap listener failed', err);
  });

  // That calendar day at the device-local hour.
  function atHour(dayString, hour) {
    var parts = dayString.split('-');
    return new Date(
      parseInt(parts[0], 10),
      parseInt(parts[1], 10) - 1,
      parseInt(parts[2], 10),
      hour, 0, 0, 0
    );
  }

  function nextAtHour(hour) {
    var now = new Date();
    var d = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hour, 0, 0, 0);
    if (d <= now) d.setDate(d.getDate() + 1);
    return d;
  }

  // The reminders in order. A dated reminder's instant is that day at the
  // device-local hour; a catch-up (no day) is the next time that hour comes
  // around. An instant that is not strictly ahead is skipped, and an id
  // already scheduled is skipped, so a dated overdue reminder that is still
  // ahead wins and the catch-up does not double it.
  function buildNotifications(reminders, hour, now) {
    var out = [];
    var scheduledIds = {};
    for (var i = 0; i < reminders.length; i++) {
      var reminder = reminders[i];
      if (!reminder || reminder.id == null || !reminder.title || !reminder.body || reminder.item_id == null) continue;
      var id = Number(reminder.id);
      if (!Number.isSafeInteger(id) || scheduledIds[id]) continue;

      var at;
      if (reminder.day == null) {
        at = nextAtHour(hour);
      } else {
        if (typeof reminder.day !== 'string') continue;
        at = atHour(reminder.day, hour);
      }
      if (!(at > now)) continue;

      scheduledIds[id] = true;
      out.push({
        id: id,
        title: reminder.title,
        body: reminder.body,
        extra: { item_id: reminder.item_id },
        schedule: { at: at, allowWhileIdle: true }
      });
    }
    return out;
  }

  async function cancelAllPending() {
    var pending = await LocalNotifications.getPending();
    if (pending && pending.notifications && pending.notifications.length > 0) {
      await LocalNotifications.cancel({
        notifications: pending.notifications.map(function (n) { return { id: n.id }; })
      });
    }
  }

  async function sync() {
    try {
      var res = await fetch(API_URL, { credentials: 'include' });
      if (res.status === 401) return;
      if (!res.ok) return;
      var data = await res.json();
      var reminders = (data && data.reminders) || [];
      var prefs = (data && data.notification_prefs) || {};

      if (!prefs.enabled) {
        await cancelAllPending();
        return;
      }

      var perm = await LocalNotifications.checkPermissions();
      if (perm.display !== 'granted') {
        perm = await LocalNotifications.requestPermissions();
        if (perm.display !== 'granted') return;
      }

      await cancelAllPending();

      var toSchedule = Number.isSafeInteger(prefs.hour)
        ? buildNotifications(reminders, prefs.hour, new Date())
        : [];
      if (toSchedule.length > 0) {
        await LocalNotifications.schedule({ notifications: toSchedule });
      }
    } catch (err) {
      console.warn('Notification sync failed', err);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sync);
  } else {
    sync();
  }
})();

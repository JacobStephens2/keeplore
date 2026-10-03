import { API_ORIGIN } from './uses/modules/publicEnvironmentVariables.js';

(function () {

  if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
    return;
  }

  var LocalNotifications = window.Capacitor.Plugins && window.Capacitor.Plugins.LocalNotifications;
  if (!LocalNotifications) return;

  var API_URL = 'https://' + API_ORIGIN + '/upcoming-interactions.php';
  var DEFAULTS = { enabled: true, hour: 9, lead_days: 3, past_due: true };

  function atHour(dateString, hour, offsetDays) {
    var parts = dateString.split('-');
    var d = new Date(
      parseInt(parts[0], 10),
      parseInt(parts[1], 10) - 1,
      parseInt(parts[2], 10),
      hour, 0, 0, 0
    );
    if (offsetDays) d.setDate(d.getDate() + offsetDays);
    return d;
  }

  function nextAtHour(hour) {
    var now = new Date();
    var d = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hour, 0, 0, 0);
    if (d <= now) d.setDate(d.getDate() + 1);
    return d;
  }

  function buildNotifications(items, prefs, now) {
    var out = [];
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (!item || !item.id || !item.use_by_date || !item.title) continue;

      if (prefs.lead_days > 0) {
        var soon = atHour(item.use_by_date, prefs.hour, -prefs.lead_days);
        if (soon > now) {
          out.push({
            id: item.id * 10 + 1,
            title: 'Due soon',
            body: item.title + ' is due ' + item.use_by_date,
            schedule: { at: soon, allowWhileIdle: true }
          });
        }
      }

      var due = atHour(item.use_by_date, prefs.hour, 0);
      if (due > now) {
        out.push({
          id: item.id * 10 + 2,
          title: 'Due today',
          body: item.title + ' is due today',
          schedule: { at: due, allowWhileIdle: true }
        });
      }

      if (prefs.past_due) {
        var past = atHour(item.use_by_date, prefs.hour, 1);
        if (past > now) {
          out.push({
            id: item.id * 10 + 3,
            title: 'Overdue',
            body: item.title + ' is overdue',
            schedule: { at: past, allowWhileIdle: true }
          });
        } else if (item.status === 'past_due') {
          out.push({
            id: item.id * 10 + 3,
            title: 'Overdue',
            body: item.title + ' is overdue (due ' + item.use_by_date + ')',
            schedule: { at: nextAtHour(prefs.hour), allowWhileIdle: true }
          });
        }
      }
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
      var items = (data && data.items) || [];
      var prefs = Object.assign({}, DEFAULTS, (data && data.notification_prefs) || {});

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

      var toSchedule = buildNotifications(items, prefs, new Date());
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

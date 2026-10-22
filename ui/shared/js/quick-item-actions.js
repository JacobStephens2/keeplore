// Quick item actions share one delegated request lifecycle. Pages own only
// their collection updates, queue removal and confirmation policy.
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.QuickItemActions = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  function presentKept(cell, isKept) {
    if (!cell) return;
    cell.dataset.kept = isKept ? '1' : '0';
    var button = cell.querySelector('.kept-toggle-btn');
    if (button) {
      button.textContent = isKept ? 'Kept' : 'Keep';
      button.setAttribute('aria-pressed', isKept ? 'true' : 'false');
    }
    var value = cell.querySelector('input[name="value"]');
    if (value) value.value = isKept ? '0' : '1';
  }

  function bind(root, options, kept) {
    if (!root) return;
    options = options || {};
    var request = options.fetch || fetch;
    var pending = new Set();
    root.addEventListener('submit', async function (event) {
      var form = event.target.closest(kept
        ? 'form.kept-toggle-form' : 'form.get-rid-of-form, form.untrack-form');
      if (!form || !root.contains(form)) return;
      event.preventDefault();
      var itemId = Number(form.querySelector('input[name="artifact_id"]').value);
      if (pending.has(itemId)) return;
      var value = form.querySelector('input[name="value"]');
      if (kept && value && value.value === '0' && options.confirmUnkeep) {
        var name = form.querySelector('input[name="artifact_name"]');
        if (!options.confirmUnkeep((name && name.value) || 'this item')) return;
      }
      pending.add(itemId);
      var row = form.closest('tr');
      var cell = form.closest('td.kept');
      var controls = Array.prototype.map.call(form.querySelectorAll('button'), function (button) {
        return { button: button, label: button.textContent, disabled: button.disabled };
      });
      controls.forEach(function (control) {
        control.button.disabled = true;
        if (!kept) control.button.textContent = 'Removing…';
      });
      try {
        var response = await request(form.action, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          body: new FormData(form),
        });
        var data = await response.json();
        if (!response.ok || !data || !data.ok) {
          options.toast((data && data.message) || (kept ? 'Request failed'
            : 'Request failed (HTTP ' + (response.ok ? 'OK' : 'error') + ')'), 'error');
          return;
        }
        var fallback;
        if (kept) {
          var isKept = data.is_kept === 1;
          presentKept(cell, isKept);
          // A list may have recreated this Item's cell while the request waited.
          root.querySelectorAll('td.kept').forEach(function (current) {
            if (Number(current.dataset.artifactId) === itemId) presentKept(current, isKept);
          });
          if (options.onChanged) options.onChanged(itemId, isKept);
          fallback = 'Updated.';
        } else {
          options.onRemoved(row, data);
          fallback = form.classList.contains('get-rid-of-form')
            ? 'Marked to get rid of.' : 'Removed from tracked collection.';
        }
        options.toast(data.message || fallback, 'success');
      } catch (error) {
        options.toast('Network error: ' + error.message, 'error');
      } finally {
        pending.delete(itemId);
        controls.forEach(function (control) {
          control.button.disabled = control.disabled;
          if (!kept) control.button.textContent = control.label;
        });
      }
    });
  }

  return {
    bindKept: function (root, options) { bind(root, options, true); },
    bindRemovals: function (root, options) { bind(root, options, false); },
  };
}));

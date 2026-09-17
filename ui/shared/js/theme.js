// Header display-mode switcher: system / light / dark (issue #7).
//
// The effective theme (data-theme="light|dark") is set before first paint by
// the early head script in private/shared/header.php. This file wires the
// #theme-switcher select afterwards: it persists the preference to
// localStorage `keeplore-theme`, re-resolves `system` against
// prefers-color-scheme, and keeps meta theme-color in sync.
//
// Value vocabulary (the cross-language seam): the allowed preference values
// and the default are rendered into the select by PHP (theme_options() /
// theme_default()) and read back out of the DOM here, so the list exists in
// exactly one place. The FALLBACK_* constants below only cover pages that
// render no switcher; they must stay in sync with private/functions.php.
(function() {
  'use strict';

  var KEY = 'keeplore-theme';
  var FALLBACK_OPTIONS = ['system', 'light', 'dark'];
  var FALLBACK_DEFAULT = 'system';
  var LIGHT_META = '#30395c';
  var DARK_META = '#0c1222';

  function switcher() {
    return document.getElementById('theme-switcher');
  }

  function allowedValues() {
    var select = switcher();
    if (!select || !select.options || select.options.length === 0) {
      return FALLBACK_OPTIONS.slice();
    }
    var values = [];
    for (var i = 0; i < select.options.length; i++) {
      values.push(select.options[i].value);
    }
    return values;
  }

  function defaultValue() {
    var select = switcher();
    if (select) {
      var fallback = select.getAttribute('data-default');
      if (fallback) {
        return fallback;
      }
    }
    return FALLBACK_DEFAULT;
  }

  function sanitize(value) {
    return allowedValues().indexOf(value) !== -1 ? value : defaultValue();
  }

  function readPref() {
    var value = null;
    try {
      value = window.localStorage.getItem(KEY);
    } catch (e) {
      value = null;
    }
    return sanitize(value);
  }

  // Effective theme is always light|dark: explicit choices apply directly,
  // while `system` (and any future non-color preference) follows the OS.
  function resolve(pref) {
    if (pref === 'light' || pref === 'dark') {
      return pref;
    }
    try {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e) {
      return 'light';
    }
  }

  function apply(pref) {
    pref = sanitize(pref);
    var effective = resolve(pref);
    document.documentElement.setAttribute('data-theme', effective);
    document.documentElement.setAttribute('data-theme-pref', pref);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      meta.setAttribute('content', effective === 'dark' ? DARK_META : LIGHT_META);
    }
    var select = switcher();
    if (select && select.value !== pref) {
      select.value = pref;
    }
    try {
      window.localStorage.setItem(KEY, pref);
    } catch (e) {
      // Private browsing etc: the early head script already applied the
      // resolved theme for this page load, so just skip persistence.
    }
  }

  function init() {
    // Re-apply so the select reflects the stored preference even when the
    // early head script was skipped (e.g. a cached page without it).
    apply(readPref());
    var select = switcher();
    if (select) {
      select.addEventListener('change', function() {
        apply(select.value);
      });
    }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      var onOsChange = function() {
        if (readPref() === 'system') {
          apply('system');
        }
      };
      if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', onOsChange);
      } else if (typeof mq.addListener === 'function') {
        mq.addListener(onOsChange);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

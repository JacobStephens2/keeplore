// Persist /artifacts/ DataTable order by column name, not index.
// Index-based order broke when Tags was inserted: [3, 'desc'] became Name
// and put "Zork I" first instead of the latest Tracking Start.
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreItemsTableSort = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var STORAGE_KEY = 'keeplore-items-table-order';

  function normalizeHeader(text) {
    return String(text || '')
      .replace(/\s+/g, ' ')
      .replace(/\s*\(\d+\)\s*$/, '')
      .trim();
  }

  function indexByName(headers) {
    var map = {};
    (headers || []).forEach(function (header, index) {
      var name = normalizeHeader(header);
      if (name && map[name] === undefined) {
        map[name] = index;
      }
    });
    return map;
  }

  function normalizeDir(dir) {
    return dir === 'asc' ? 'asc' : 'desc';
  }

  function namedToDataTableOrder(headers, namedOrder) {
    var map = indexByName(headers);
    var order = [];
    (namedOrder || []).forEach(function (entry) {
      if (!entry || !entry.column) {
        return;
      }
      var index = map[normalizeHeader(entry.column)];
      if (index === undefined) {
        return;
      }
      order.push([index, normalizeDir(entry.dir)]);
    });
    return order;
  }

  function readStored(storage) {
    if (!storage || typeof storage.getItem !== 'function') {
      return null;
    }
    try {
      return storage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function parseNamed(raw) {
    if (typeof raw !== 'string' || raw === '') {
      return null;
    }
    try {
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : null;
    } catch (e) {
      return null;
    }
  }

  function restore(headers, storage, fallbackNamed) {
    var stored = namedToDataTableOrder(headers, parseNamed(readStored(storage)));
    if (stored.length) {
      return stored;
    }
    return namedToDataTableOrder(headers, fallbackNamed);
  }

  function persist(headers, storage, dataTableOrder) {
    if (!storage || typeof storage.setItem !== 'function') {
      return;
    }
    var named = [];
    (dataTableOrder || []).forEach(function (pair) {
      if (!pair) {
        return;
      }
      var header = headers[pair[0]];
      if (header == null) {
        return;
      }
      named.push({
        column: normalizeHeader(header),
        dir: normalizeDir(pair[1]),
      });
    });
    try {
      storage.setItem(STORAGE_KEY, JSON.stringify(named));
    } catch (e) {
      // Private mode / quota: keep the current visit sorted; skip persist.
    }
  }

  return {
    restore: restore,
    persist: persist,
  };
}));

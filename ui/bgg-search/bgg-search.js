// Search BGG adapter for KeeploreListTable: click a column header to sort
// the results. Rows stay server-rendered; each carries its values as data-*.
(function (root, factory) {
  var listTable = root.KeeploreListTable;
  if (!listTable && typeof require === 'function') {
    listTable = require('../shared/js/list-table.js');
  }
  var api = factory(listTable);
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreBggSearch = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (ListTable) {
  'use strict';

  var NUMBER_KEYS = ['best', 'votes', 'age', 'rank', 'average'];

  function numberOrNull(raw) {
    if (raw == null || String(raw).trim() === '') {
      return null;
    }
    var n = Number(raw);
    return Number.isNaN(n) ? null : n;
  }

  // A game with no rank, age or average sorts last whichever way.
  function compareGames(a, b, key, dir) {
    var av = a[key];
    var bv = b[key];
    if (key === 'name') {
      return ListTable.compareValues(String(av || '').toLowerCase(), String(bv || '').toLowerCase(), dir);
    }
    if (key === 'kept') {
      return ListTable.compareValues(av ? 1 : 0, bv ? 1 : 0, dir);
    }
    if (av == null && bv == null) {
      return 0;
    }
    if (av == null) {
      return 1;
    }
    if (bv == null) {
      return -1;
    }
    return ListTable.compareValues(av, bv, dir);
  }

  function sortGames(games, sorts) {
    return ListTable.sortRecords(games, sorts, compareGames);
  }

  // $index keeps the server's order (best BGG rank first) for ties.
  function gameFromRow(tr, index) {
    var game = { id: index, name: tr.getAttribute('data-name') || '' };
    NUMBER_KEYS.forEach(function (key) {
      game[key] = numberOrNull(tr.getAttribute('data-' + key));
    });
    game.kept = tr.getAttribute('data-kept') === '1';
    game.row = tr;
    return game;
  }

  function bind(doc) {
    var table = doc.getElementById('bgg-search-results');
    var tbody = table && table.querySelector('tbody');
    if (!tbody || !ListTable) {
      return;
    }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    ListTable.mount({
      document: doc,
      table: table,
      tbody: tbody,
      sortSummary: doc.getElementById('bgg-search-sort-summary'),
      records: rows.map(gameFromRow),
      match: function () {
        return true;
      },
      compare: compareGames,
      sorts: [{ key: 'rank', dir: 'asc' }],
      row: function (game) {
        return game.row;
      },
      columnCount: 7,
      pageLength: rows.length || 1,
    });
  }

  if (typeof document !== 'undefined' && typeof module === 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        bind(document);
      });
    } else {
      bind(document);
    }
  }

  return {
    compareGames: compareGames,
    sortGames: sortGames,
    gameFromRow: gameFromRow,
  };
}));

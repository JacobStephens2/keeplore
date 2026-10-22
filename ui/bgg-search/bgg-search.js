// Search BGG adapter for KeeploreListTable: click a column header to sort
// the results. Rows stay server-rendered. The sort reads the same row
// records, embedded as JSON, and pairs each with the row in that position.
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

  // A game with no rank, age, average, Best or score sorts last whichever way.
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

  // The embedded records, each paired with the row in the same position.
  // A count that disagrees, or JSON that is not a list, is not sorted.
  function recordsForTable(doc) {
    var table = doc.getElementById('bgg-search-results');
    var tbody = table && table.querySelector('tbody');
    var node = doc.getElementById('bgg-search-rows');
    if (!tbody || !node) {
      return null;
    }
    var records;
    try {
      records = JSON.parse(node.textContent || '');
    } catch (error) {
      return null;
    }
    if (!Array.isArray(records)) {
      return null;
    }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (records.length !== rows.length) {
      return null;
    }
    return records.map(function (record, index) {
      var game = {};
      Object.keys(record).forEach(function (key) {
        game[key] = record[key];
      });
      game.row = rows[index];
      return game;
    });
  }

  function bind(doc) {
    var table = doc.getElementById('bgg-search-results');
    var tbody = table && table.querySelector('tbody');
    var records = recordsForTable(doc);
    if (!tbody || !ListTable || !records) {
      return;
    }
    ListTable.mount({
      document: doc,
      table: table,
      tbody: tbody,
      sortSummary: doc.getElementById('bgg-search-sort-summary'),
      records: records,
      match: function () {
        return true;
      },
      compare: compareGames,
      sorts: [{ key: 'rank', dir: 'asc' }],
      row: function (game) {
        return game.row;
      },
      columnCount: table.querySelectorAll('thead th').length,
      pageLength: records.length || 1,
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
    recordsForTable: recordsForTable,
  };
}));

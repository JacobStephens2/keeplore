// Users adapter for KeeploreListTable. Search lives in the page HTML
// so it can autofocus before this script runs. Rows stay server-rendered.
(function (root, factory) {
  var listTable = root.KeeploreListTable;
  if (!listTable && typeof require === 'function') {
    listTable = require('./list-table.js');
  }
  var api = factory(listTable);
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreUsersList = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (ListTable) {
  'use strict';

  function userMatchesSearch(user, query) {
    return ListTable.fieldsMatchSearch([
      user.name || '',
      user.gender || '',
      user.age == null || user.age === '' ? '' : String(user.age),
      user.id == null ? '' : String(user.id),
    ], query);
  }

  function compareUsers(a, b, key, dir) {
    var av = a[key];
    var bv = b[key];
    if (key === 'age') {
      var aMissing = av == null || av === '';
      var bMissing = bv == null || bv === '';
      if (aMissing && bMissing) {
        return 0;
      }
      if (aMissing) {
        return 1;
      }
      if (bMissing) {
        return -1;
      }
      av = Number(av) || 0;
      bv = Number(bv) || 0;
    } else if (key === 'id') {
      av = Number(av) || 0;
      bv = Number(bv) || 0;
    } else {
      av = String(av || '').toLowerCase();
      bv = String(bv || '').toLowerCase();
    }
    return ListTable.compareValues(av, bv, dir);
  }

  function sortUsers(users, sort) {
    return ListTable.sortRecords(users, sort ? [sort] : [], compareUsers);
  }

  function parseAge(raw) {
    if (raw == null || raw === '') {
      return null;
    }
    var n = Number(raw);
    return Number.isNaN(n) ? null : n;
  }

  function usersFromTable(tbody) {
    return Array.prototype.map.call(tbody.querySelectorAll('tr'), function (tr) {
      return {
        id: Number(tr.getAttribute('data-id')),
        name: tr.getAttribute('data-name') || '',
        gender: tr.getAttribute('data-gender') || '',
        age: parseAge(tr.getAttribute('data-age')),
        row: tr,
      };
    });
  }

  function bind(doc) {
    var search = doc.getElementById('users-search');
    var tbody = doc.getElementById('users-list-body');
    var table = doc.getElementById('users');
    var nameHeader = doc.getElementById('users-name-header');
    var pager = doc.getElementById('users-list-pager');
    if (search) {
      search.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          search.blur();
        }
      });
    }
    if (!search || !tbody || !table || !ListTable) {
      return;
    }

    ListTable.mount({
      document: doc,
      search: search,
      table: table,
      tbody: tbody,
      nameHeader: nameHeader,
      pager: pager,
      records: usersFromTable(tbody),
      match: userMatchesSearch,
      compare: compareUsers,
      sorts: [{ key: 'id', dir: 'desc' }],
      row: function (user) {
        return user.row;
      },
      columnCount: 5,
      pageLength: Number(table.getAttribute('data-page-length')) || 100,
      emptyMessage: 'No users yet.',
      noMatchMessage: 'No users match.',
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
    userMatchesSearch: userMatchesSearch,
    sortUsers: sortUsers,
  };
}));

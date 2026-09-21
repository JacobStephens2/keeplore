// Filter, sort, and page the /users/ table. Search lives in the page HTML
// so it can autofocus before this script runs. Rows stay server-rendered.
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreUsersList = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var PAGE_LENGTH = 100;
  var COLUMN_COUNT = 5;
  var DEFAULT_SORTS = [{ key: 'id', dir: 'desc' }];

  function userMatchesSearch(user, query) {
    var q = String(query || '').trim().toLowerCase();
    if (!q) {
      return true;
    }
    var haystack = [
      user.name || '',
      user.gender || '',
      user.age == null || user.age === '' ? '' : String(user.age),
      user.id == null ? '' : String(user.id),
    ].join(' ').toLowerCase();
    return q.split(/\s+/).every(function (part) {
      return haystack.indexOf(part) !== -1;
    });
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
    if (av < bv) {
      return dir === 'desc' ? 1 : -1;
    }
    if (av > bv) {
      return dir === 'desc' ? -1 : 1;
    }
    return 0;
  }

  function sortUsers(users, sorts) {
    var copy = users.slice();
    copy.sort(function (a, b) {
      var list = sorts && sorts.length ? sorts : DEFAULT_SORTS;
      for (var i = 0; i < list.length; i++) {
        var result = compareUsers(a, b, list[i].key, list[i].dir);
        if (result !== 0) {
          return result;
        }
      }
      return (Number(a.id) || 0) - (Number(b.id) || 0);
    });
    return copy;
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (name) {
        var value = attrs[name];
        if (value === null || value === undefined || value === false) {
          return;
        }
        if (name === 'className') {
          node.className = value;
        } else if (name === 'text') {
          node.textContent = value;
        } else if (value === true) {
          node.setAttribute(name, '');
        } else {
          node.setAttribute(name, value);
        }
      });
    }
    (children || []).forEach(function (child) {
      if (child) {
        node.appendChild(child);
      }
    });
    return node;
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

  function statusRow(message) {
    return el('tr', { className: 'items-list-status' }, [
      el('td', { colspan: String(COLUMN_COUNT), text: message }),
    ]);
  }

  function mount(doc) {
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
    if (!search || !tbody || !table) {
      return;
    }

    var state = {
      users: usersFromTable(tbody),
      sorts: DEFAULT_SORTS.slice(),
      page: 0,
    };

    function applyAriaSort() {
      table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
        th.removeAttribute('aria-sort');
      });
      var primary = state.sorts[0];
      if (!primary) {
        return;
      }
      var active = table.querySelector('thead th[data-sort="' + primary.key + '"]');
      if (active) {
        active.setAttribute('aria-sort', primary.dir === 'desc' ? 'descending' : 'ascending');
      }
    }
    applyAriaSort();

    function filteredUsers() {
      var matched = state.users.filter(function (user) {
        return userMatchesSearch(user, search.value);
      });
      return sortUsers(matched, state.sorts);
    }

    function render() {
      tbody.textContent = '';
      var rows = filteredUsers();
      var total = state.users.length;
      var shown = rows.length;
      if (nameHeader) {
        nameHeader.textContent = search.value.trim()
          ? 'Name (' + shown + ' of ' + total + ')'
          : 'Name (' + total + ')';
      }
      if (rows.length === 0) {
        tbody.appendChild(statusRow(
          search.value.trim() ? 'No users match.' : 'No users yet.'
        ));
        if (pager) {
          pager.textContent = '';
        }
        return;
      }
      var pageLength = Number(table.getAttribute('data-page-length')) || PAGE_LENGTH;
      var pageCount = Math.max(1, Math.ceil(rows.length / pageLength));
      if (state.page >= pageCount) {
        state.page = pageCount - 1;
      }
      if (state.page < 0) {
        state.page = 0;
      }
      var start = state.page * pageLength;
      var pageRows = rows.slice(start, start + pageLength);
      var fragment = doc.createDocumentFragment();
      pageRows.forEach(function (user) {
        fragment.appendChild(user.row);
      });
      tbody.appendChild(fragment);

      if (pager) {
        pager.textContent = '';
        var end = Math.min(start + pageRows.length, rows.length);
        pager.appendChild(el('span', {
          text: 'Showing ' + (start + 1) + ' to ' + end + ' of ' + rows.length,
        }));
        if (pageCount > 1) {
          var prev = el('button', { type: 'button', text: 'Previous' });
          prev.disabled = state.page === 0;
          prev.addEventListener('click', function () {
            state.page -= 1;
            render();
          });
          var next = el('button', { type: 'button', text: 'Next' });
          next.disabled = state.page >= pageCount - 1;
          next.addEventListener('click', function () {
            state.page += 1;
            render();
          });
          pager.appendChild(prev);
          pager.appendChild(next);
        }
      }
    }

    search.addEventListener('input', function () {
      state.page = 0;
      render();
    });

    table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        var current = state.sorts[0];
        var dir = current && current.key === key && current.dir === 'desc' ? 'asc' : 'desc';
        state.sorts = [{ key: key, dir: dir }];
        applyAriaSort();
        render();
      });
    });

    render();
  }

  return {
    userMatchesSearch: userMatchesSearch,
    sortUsers: sortUsers,
    mount: mount,
  };
}));

if (typeof document !== 'undefined' && typeof module === 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      KeeploreUsersList.mount(document);
    });
  } else {
    KeeploreUsersList.mount(document);
  }
}

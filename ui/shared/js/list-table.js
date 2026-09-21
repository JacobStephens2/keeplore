// Search, sort, page, and bind a Keeplore list table.
// Callers supply match fields, compare, and how to turn a record into a row.
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreListTable = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var PAGE_LENGTH = 100;

  function fieldsMatchSearch(fields, query) {
    var q = String(query || '').trim().toLowerCase();
    if (!q) {
      return true;
    }
    var haystack = (fields || []).join(' ').toLowerCase();
    return q.split(/\s+/).every(function (part) {
      return haystack.indexOf(part) !== -1;
    });
  }

  function compareValues(av, bv, dir) {
    if (av < bv) {
      return dir === 'desc' ? 1 : -1;
    }
    if (av > bv) {
      return dir === 'desc' ? -1 : 1;
    }
    return 0;
  }

  function sortRecords(records, sorts, compare) {
    var copy = records.slice();
    var list = sorts && sorts.length ? sorts : [];
    copy.sort(function (a, b) {
      for (var i = 0; i < list.length; i++) {
        var result = compare(a, b, list[i].key, list[i].dir);
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
        } else if (name === 'dataset') {
          Object.keys(value).forEach(function (key) {
            node.dataset[key] = value[key];
          });
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

  function statusRow(columnCount, message) {
    return el('tr', { className: 'list-status' }, [
      el('td', { colspan: String(columnCount), text: message }),
    ]);
  }

  function mount(opts) {
    var search = opts.search;
    var table = opts.table;
    var tbody = opts.tbody;
    var nameHeader = opts.nameHeader;
    var pager = opts.pager;
    var doc = opts.document || document;
    var match = opts.match;
    var compare = opts.compare;
    var rowOf = opts.row;
    var columnCount = opts.columnCount || 1;
    var pageLength = opts.pageLength || PAGE_LENGTH;
    var emptyMessage = opts.emptyMessage || 'No rows yet.';
    var noMatchMessage = opts.noMatchMessage || 'No rows match.';
    var onSort = opts.onSort;
    var state = {
      records: opts.records || [],
      sorts: (opts.sorts && opts.sorts.length) ? opts.sorts.slice() : [],
      page: 0,
      status: opts.status || null,
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

    function render() {
      tbody.textContent = '';
      if (state.status) {
        tbody.appendChild(statusRow(columnCount, state.status));
        if (pager) {
          pager.textContent = '';
        }
        return;
      }
      var query = search ? search.value : '';
      var matched = state.records.filter(function (record) {
        return match(record, query);
      });
      var rows = sortRecords(matched, state.sorts, compare);
      var total = state.records.length;
      var shown = rows.length;
      if (nameHeader) {
        nameHeader.textContent = String(query || '').trim()
          ? 'Name (' + shown + ' of ' + total + ')'
          : 'Name (' + total + ')';
      }
      if (rows.length === 0) {
        tbody.appendChild(statusRow(
          columnCount,
          String(query || '').trim() ? noMatchMessage : emptyMessage
        ));
        if (pager) {
          pager.textContent = '';
        }
        return;
      }
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
      pageRows.forEach(function (record) {
        fragment.appendChild(rowOf(record));
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

    if (search) {
      search.addEventListener('input', function () {
        state.page = 0;
        render();
      });
    }

    table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        var current = state.sorts[0];
        var dir = current && current.key === key && current.dir === 'desc' ? 'asc' : 'desc';
        state.sorts = [{ key: key, dir: dir }];
        applyAriaSort();
        if (typeof onSort === 'function') {
          onSort(state.sorts);
        }
        render();
      });
    });

    applyAriaSort();
    render();

    return {
      setRecords: function (records) {
        state.records = records || [];
        state.status = null;
        state.page = 0;
        render();
      },
      setStatus: function (message) {
        state.status = message;
        render();
      },
    };
  }

  return {
    fieldsMatchSearch: fieldsMatchSearch,
    compareValues: compareValues,
    sortRecords: sortRecords,
    el: el,
    mount: mount,
  };
}));

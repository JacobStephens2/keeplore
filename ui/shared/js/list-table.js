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

  /**
   * The sort list after a header click. A plain click sorts by that column
   * alone, flipping it when it already leads alone; an additive (Shift) click
   * adds it as the next tie-breaker, or flips it where it already sits.
   */
  function nextSorts(sorts, key, additive) {
    var list = (sorts || []).map(function (sort) {
      return { key: sort.key, dir: sort.dir };
    });
    if (!additive) {
      var current = list[0];
      return [{ key: key, dir: current && current.key === key && current.dir === 'desc' ? 'asc' : 'desc' }];
    }
    for (var i = 0; i < list.length; i++) {
      if (list[i].key === key) {
        list[i].dir = list[i].dir === 'desc' ? 'asc' : 'desc';
        return list;
      }
    }
    list.push({ key: key, dir: 'desc' });
    return list;
  }

  // "Sorted by Tags (descending), then Gyges (descending)", or '' with no sort.
  function describeSorts(sorts, labels) {
    return (sorts || []).map(function (sort, index) {
      var label = (labels && labels[sort.key]) || sort.key;
      return (index === 0 ? 'Sorted by ' : 'then ') + label
        + (sort.dir === 'desc' ? ' (descending)' : ' (ascending)');
    }).join(', ');
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
    var sortSummary = opts.sortSummary;
    var state = {
      records: opts.records || [],
      sorts: (opts.sorts && opts.sorts.length) ? opts.sorts.slice() : [],
      page: 0,
      status: opts.status || null,
    };

    var sortHeaders = Array.prototype.slice.call(table.querySelectorAll('thead th[data-sort]'));

    function headerLabels() {
      var labels = {};
      sortHeaders.forEach(function (th) {
        labels[th.getAttribute('data-sort')] = String(th.textContent || '')
          .replace(/\s+/g, ' ')
          .replace(/\s*\(\d+( of \d+)?\)\s*$/, '')
          .trim();
      });
      return labels;
    }

    // The summary line, plus a "Then by" menu so a touch screen, which has
    // no Shift key, can add a tie-breaker too.
    function renderSortSummary() {
      if (!sortSummary) {
        return;
      }
      var labels = headerLabels();
      sortSummary.textContent = '';
      sortSummary.appendChild(el('span', { text: describeSorts(state.sorts, labels) }));
      var sorted = {};
      state.sorts.forEach(function (sort) {
        sorted[sort.key] = true;
      });
      var choices = sortHeaders.filter(function (th) {
        return !sorted[th.getAttribute('data-sort')];
      });
      if (!state.sorts.length || !choices.length) {
        return;
      }
      var select = el('select', { 'aria-label': 'Then sort by' }, [el('option', { value: '', text: 'Then by…' })]
        .concat(choices.map(function (th) {
          var key = th.getAttribute('data-sort');
          return el('option', { value: key, text: labels[key] });
        })));
      select.addEventListener('change', function () {
        if (select.value) {
          setSorts(nextSorts(state.sorts, select.value, true));
        }
      });
      sortSummary.appendChild(select);
    }

    function applyAriaSort() {
      sortHeaders.forEach(function (th) {
        th.removeAttribute('aria-sort');
        th.removeAttribute('data-sort-rank');
      });
      state.sorts.forEach(function (sort, index) {
        var th = table.querySelector('thead th[data-sort="' + sort.key + '"]');
        if (!th) {
          return;
        }
        if (index === 0) {
          th.setAttribute('aria-sort', sort.dir === 'desc' ? 'descending' : 'ascending');
        }
        // Numbered only when there are levels to tell apart.
        if (state.sorts.length > 1) {
          th.setAttribute('data-sort-rank', String(index + 1));
        }
      });
      renderSortSummary();
    }

    function setSorts(sorts) {
      state.sorts = sorts;
      applyAriaSort();
      if (typeof onSort === 'function') {
        onSort(state.sorts);
      }
      render();
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

    sortHeaders.forEach(function (th) {
      th.addEventListener('click', function (event) {
        setSorts(nextSorts(state.sorts, th.getAttribute('data-sort'), !!(event && event.shiftKey)));
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
    nextSorts: nextSorts,
    describeSorts: describeSorts,
    sortRecords: sortRecords,
    el: el,
    mount: mount,
  };
}));

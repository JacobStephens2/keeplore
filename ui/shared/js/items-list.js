// Items list adapter for KeeploreListTable. The row carries the sort values
// beside the cell text; this compares the values and does not read the labels.
(function (root, factory) {
  var listTable = root.KeeploreListTable;
  if (!listTable && typeof require === 'function') {
    listTable = require('./list-table.js');
  }
  var quickItemActions = root.QuickItemActions;
  if (!quickItemActions && typeof require === 'function') {
    quickItemActions = require('./quick-item-actions.js');
  }
  var api = factory(listTable, quickItemActions);
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreItemsList = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (ListTable, QuickItemActions) {
  'use strict';

  var el = ListTable.el;
  var SORT_FALLBACK = [
    { column: 'Tracking Start', dir: 'desc' },
    { column: 'Recent Interaction', dir: 'desc' },
    { column: 'Interact By', dir: 'desc' },
  ];

  function columnKeys(tableEl) {
    return Array.prototype.map.call(tableEl.querySelectorAll('thead th[data-sort]'), function (th) {
      return th.getAttribute('data-sort');
    });
  }

  function headerTexts(tableEl) {
    return Array.prototype.map.call(tableEl.querySelectorAll('thead th'), function (th) {
      return th.textContent;
    });
  }

  function sortsFromOrder(order, keys) {
    var sorts = [];
    for (var i = 0; i < order.length; i++) {
      var key = keys[order[i][0]];
      if (key) {
        sorts.push({ key: key, dir: order[i][1] });
      }
    }
    return sorts;
  }

  function orderFromSorts(sorts, keys) {
    var order = [];
    for (var i = 0; i < sorts.length; i++) {
      var index = keys.indexOf(sorts[i].key);
      if (index >= 0) {
        order.push([index, sorts[i].dir]);
      }
    }
    return order;
  }

  function restoreSorts(headers, keys) {
    var restored = [];
    if (window.KeeploreItemsTableSort && typeof KeeploreItemsTableSort.restore === 'function') {
      restored = KeeploreItemsTableSort.restore(headers, window.localStorage, SORT_FALLBACK);
    }
    var sorts = sortsFromOrder(restored || [], keys);
    if (sorts.length) {
      return sorts;
    }
    return [
      { key: 'acq', dir: 'desc' },
      { key: 'most_recent_use', dir: 'desc' },
      { key: 'use_by', dir: 'desc' },
    ];
  }

  function persistSorts(headers, sorts, keys) {
    if (!window.KeeploreItemsTableSort || typeof KeeploreItemsTableSort.persist !== 'function') {
      return;
    }
    KeeploreItemsTableSort.persist(headers, window.localStorage, orderFromSorts(sorts, keys));
  }

  function configFromPage() {
    var node = document.getElementById('items-list-config');
    if (!node) {
      return null;
    }
    try {
      return JSON.parse(node.textContent);
    } catch (err) {
      console.error('[ItemsList] invalid config', err);
      return null;
    }
  }

  function itemMatchesSearch(item, query) {
    return ListTable.fieldsMatchSearch([
      item.title || '',
      item.type || '',
      (item.tags || []).join(' '),
    ], query);
  }

  var BGG_RATING_KEY = 'bgg_rating:';

  // A missing Players, Time or Age sorts last whichever way. One number
  // cannot mean last in both directions.
  function missingSortsLast(av, bv, dir) {
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

  // A missing average or rating is below every score, so a descending sort
  // still leads with the best and an ascending sort still leads with unrated rows.
  function belowEveryScore(value) {
    return value == null || !isFinite(Number(value)) ? -1 : Number(value);
  }

  function bggRating(item, reviewer) {
    return (item.bgg_ratings || {})[reviewer] || null;
  }

  // A numeric rating shows the row's score_text. A comment with no score reads "Comment".
  function bggRatingLabel(rating) {
    return rating.rating == null ? 'Comment' : rating.score_text;
  }

  function compareItems(a, b, key, dir) {
    var av = a[key];
    var bv = b[key];
    if (key.indexOf(BGG_RATING_KEY) === 0) {
      var reviewer = key.slice(BGG_RATING_KEY.length);
      var ar = bggRating(a, reviewer);
      var br = bggRating(b, reviewer);
      av = belowEveryScore(ar ? ar.sort : null);
      bv = belowEveryScore(br ? br.sort : null);
    } else if (key === 'players' || key === 'time' || key === 'age') {
      return missingSortsLast(a[key + '_sort'], b[key + '_sort'], dir);
    } else if (key === 'is_kept') {
      av = av ? 1 : 0;
      bv = bv ? 1 : 0;
    } else if (key === 'tags') {
      av = (av || []).join(', ');
      bv = (bv || []).join(', ');
    } else if (key === 'title' || key === 'type') {
      av = String(av || '').toLowerCase();
      bv = String(bv || '').toLowerCase();
    } else if (key === 'acq' || key === 'most_recent_use' || key === 'use_by') {
      av = av || '';
      bv = bv || '';
    } else if (key === 'avg_time') {
      av = Number(av) || 0;
      bv = Number(bv) || 0;
    } else if (key === 'bgg_average') {
      av = belowEveryScore(a.bgg_average_sort);
      bv = belowEveryScore(b.bgg_average_sort);
    } else if (key === 'candidate') {
      av = av ? 1 : 0;
      bv = bv ? 1 : 0;
    } else {
      av = av == null ? '' : av;
      bv = bv == null ? '' : bv;
    }
    return ListTable.compareValues(av, bv, dir);
  }

  function sortItems(items, sorts) {
    return ListTable.sortRecords(items, sorts, compareItems);
  }

  function renderKeptCell(item, config) {
    var cell = el('td', {
      className: 'kept',
      dataset: { artifactId: String(item.id), kept: item.is_kept ? '1' : '0' },
    });
    if (config.isGuest) {
      cell.textContent = item.is_kept ? 'yes' : 'no';
      return cell;
    }
    var form = el('form', {
      className: 'kept-toggle-form',
      method: 'post',
      action: config.keptToggleUrl,
      style: 'margin:0;',
    }, [
      el('input', { type: 'hidden', name: 'csrf_token', value: config.csrfToken }),
      el('input', { type: 'hidden', name: 'artifact_id', value: String(item.id) }),
      el('input', { type: 'hidden', name: 'artifact_name', value: item.title }),
      el('input', { type: 'hidden', name: 'value', value: item.is_kept ? '0' : '1' }),
      el('input', { type: 'hidden', name: 'return_to', value: 'index' }),
      el('button', {
        type: 'submit',
        className: 'kept-toggle-btn',
        'aria-pressed': item.is_kept ? 'true' : 'false',
        text: item.is_kept ? 'Kept' : 'Keep',
      }),
    ]);
    cell.appendChild(form);
    return cell;
  }

  function renderBggRatingCell(item, reviewer) {
    var cell = el('td', { className: 'bgg-rating' });
    var rating = bggRating(item, reviewer);
    if (!rating) {
      return cell;
    }
    if (!rating.comment) {
      cell.textContent = bggRatingLabel(rating);
      return cell;
    }
    cell.appendChild(el('button', {
      type: 'button',
      className: 'bgg-rating-btn',
      'aria-haspopup': 'dialog',
      'aria-label': bggRatingLabel(rating) + ', read ' + reviewer + '\'s comment on ' + item.title,
      dataset: { itemId: String(item.id), reviewer: reviewer },
      text: bggRatingLabel(rating),
    }));
    return cell;
  }

  function openBggRatingDialog(dialog, item, reviewer) {
    var rating = bggRating(item, reviewer);
    if (!dialog || !rating) {
      return;
    }
    document.getElementById('bgg-rating-dialog-title').textContent = reviewer + ' on ' + item.title;
    document.getElementById('bgg-rating-dialog-score').textContent = rating.rating == null
      ? 'Commented without a rating'
      : 'Rated ' + bggRatingLabel(rating) + ' out of 10';
    if (rating.manual) {
      document.getElementById('bgg-rating-dialog-score').textContent += ' (entered by hand)';
    }
    document.getElementById('bgg-rating-dialog-comment').textContent = rating.comment || '';
    var link = document.getElementById('bgg-rating-dialog-link');
    link.hidden = !rating.url;
    link.href = rating.url || '#';
    link.textContent = item.title + ' on ' + rating.site_name;
    dialog.showModal();
  }

  // One cell per key in config.columns, the list items_list_columns() gives
  // the page, so headings and cells never fall out of step.
  function renderCell(item, config, key) {
    if (key.indexOf(BGG_RATING_KEY) === 0) {
      return renderBggRatingCell(item, key.slice(BGG_RATING_KEY.length));
    }
    switch (key) {
      case 'is_kept':
        return renderKeptCell(item, config);
      case 'title':
        return el('td', { className: 'artifact_title' }, [
          el('a', {
            className: 'table-action',
            href: config.itemUrlPrefix + encodeURIComponent(item.id),
            text: item.title,
          }),
          // "Azul, 2–4 (2), 8 yrs" for sharing, from items_list_copy_text().
          item.copy_text ? el('button', {
            type: 'button',
            className: 'copy-item-btn',
            title: 'Copy "' + item.copy_text + '"',
            'aria-label': 'Copy ' + item.title + "'s name, players and age",
            dataset: { copyText: item.copy_text },
            text: 'Copy',
          }) : null,
        ]);
      case 'tags':
        return el('td', { text: (item.tags || []).join(', ') });
      case 'acq':
        return el('td', { className: 'date acquisition', text: item.acq });
      case 'most_recent_use':
        return el('td', { className: 'date most_recent_use', text: item.most_recent_use });
      case 'use_by':
        var useByCell = el('td', { className: 'date use_by', text: item.use_by });
        if (item.use_by_overdue) {
          useByCell.style.color = 'red';
        }
        return useByCell;
      case 'avg_time':
        return el('td', { text: String(item.avg_time) });
      case 'candidate':
        return el('td', { text: item.candidate ? 'Yes' : 'No' });
      case 'type':
        // No class: td.type elsewhere caps width at 12ch, which would wrap it.
        return el('td', { text: item.type });
      default:
        // players, time, age: plain text, with the key as the class.
        return el('td', { className: key, text: item[key] == null ? '' : String(item[key]) });
    }
  }

  function renderRow(item, config) {
    return el('tr', {}, (config.columns || []).map(function (key) {
      return renderCell(item, config, key);
    }));
  }

  function showToast(toastEl, message, kind) {
    if (!toastEl) {
      window.alert(message);
      return;
    }
    toastEl.textContent = message;
    toastEl.classList.remove('toast-success', 'toast-error', 'is-visible');
    toastEl.classList.add(kind === 'error' ? 'toast-error' : 'toast-success');
    void toastEl.offsetWidth;
    toastEl.classList.add('is-visible');
    if (toastEl._timer) {
      clearTimeout(toastEl._timer);
    }
    toastEl._timer = setTimeout(function () {
      toastEl.classList.remove('is-visible');
    }, 3500);
  }

  function mount() {
    var config = configFromPage();
    var search = document.getElementById('items-search');
    var tbody = document.getElementById('items-list-body');
    var table = document.getElementById('artifacts');
    var nameHeader = document.getElementById('items-name-header');
    var pager = document.getElementById('items-list-pager');
    var toastEl = document.getElementById('items-toast');
    var ratingDialog = document.getElementById('bgg-rating-dialog');
    if (!config || !search || !tbody || !table || !ListTable) {
      return;
    }

    var keys = columnKeys(table);
    var headers = headerTexts(table);
    var items = [];
    var list = ListTable.mount({
      search: search,
      table: table,
      tbody: tbody,
      nameHeader: nameHeader,
      pager: pager,
      sortSummary: document.getElementById('items-sort-summary'),
      match: itemMatchesSearch,
      compare: compareItems,
      sorts: restoreSorts(headers, keys),
      row: function (item) {
        return renderRow(item, config);
      },
      columnCount: (config.columns || []).length || 1,
      pageLength: config.pageLength || 100,
      emptyMessage: config.emptyMessage || 'No items yet.',
      noMatchMessage: 'No items match.',
      status: 'Loading items…',
      onSort: function (sorts) {
        persistSorts(headers, sorts, keys);
      },
    });

    tbody.addEventListener('click', function (event) {
      var copyButton = event.target.closest('.copy-item-btn');
      if (copyButton) {
        var text = copyButton.dataset.copyText;
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
          window.prompt('Copy this:', text);
          return;
        }
        navigator.clipboard.writeText(text).then(function () {
          showToast(toastEl, 'Copied "' + text + '"', 'success');
        }, function () {
          window.prompt('Copy this:', text);
        });
        return;
      }
      var button = event.target.closest('.bgg-rating-btn');
      if (!button) {
        return;
      }
      var itemId = Number(button.dataset.itemId);
      var item = items.find(function (row) {
        return row.id === itemId;
      });
      if (item) {
        openBggRatingDialog(ratingDialog, item, button.dataset.reviewer);
      }
    });

    if (ratingDialog) {
      // A backdrop click targets the dialog itself, as does one on its padding,
      // so close only when the click falls outside the panel's box.
      ratingDialog.addEventListener('click', function (event) {
        var box = ratingDialog.getBoundingClientRect();
        var outside = event.clientX < box.left || event.clientX > box.right ||
          event.clientY < box.top || event.clientY > box.bottom;
        if (event.target === ratingDialog && outside) {
          ratingDialog.close();
        }
      });
    }

    QuickItemActions.bindKept(tbody, {
      toast: function (message, kind) { showToast(toastEl, message, kind); },
      onChanged: function (itemId, isKept) {
        var item = items.find(function (row) { return row.id === itemId; });
        if (item) item.is_kept = isKept;
      },
    });

    fetch(config.dataUrl, { credentials: 'include', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Request failed: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        items = Array.isArray(data.items) ? data.items : [];
        list.setRecords(items);
      })
      .catch(function (error) {
        list.setStatus('Could not load items: ' + error.message);
      });
  }

  if (typeof document !== 'undefined' && typeof module === 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mount);
    } else {
      mount();
    }
  }

  return {
    compareItems: compareItems,
    sortItems: sortItems,
  };
}));

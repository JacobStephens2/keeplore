(function () {
  var ListTable = window.KeeploreListTable;
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

  function compareItems(a, b, key, dir) {
    var av = a[key];
    var bv = b[key];
    if (key === 'is_kept') {
      av = av ? 1 : 0;
      bv = bv ? 1 : 0;
    } else if (key === 'tags') {
      av = (av || []).join(', ');
      bv = (bv || []).join(', ');
    } else if (key === 'title' || key === 'type' || key === 'ss') {
      av = String(av || '').toLowerCase();
      bv = String(bv || '').toLowerCase();
    } else if (key === 'acq' || key === 'most_recent_use' || key === 'use_by') {
      av = av || '';
      bv = bv || '';
    } else if (key === 'avg_time') {
      av = Number(av) || 0;
      bv = Number(bv) || 0;
    } else if (key === 'candidate') {
      av = av ? 1 : 0;
      bv = bv ? 1 : 0;
    } else {
      av = av == null ? '' : av;
      bv = bv == null ? '' : bv;
    }
    return ListTable.compareValues(av, bv, dir);
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

  function renderRow(item, config) {
    var useByCell = el('td', {
      className: 'date use_by',
      text: item.use_by,
    });
    if (item.use_by_overdue) {
      useByCell.style.color = 'red';
    }
    var cells = [
      renderKeptCell(item, config),
      el('td', { className: 'artifact_title' }, [
        el('a', {
          className: 'table-action',
          href: config.itemUrlPrefix + encodeURIComponent(item.id),
          text: item.title,
        }),
      ]),
      el('td', { text: item.type }),
      el('td', { text: (item.tags || []).join(', ') }),
      el('td', { className: 'date acquisition', text: item.acq }),
      el('td', { className: 'date most_recent_use', text: item.most_recent_use }),
      useByCell,
    ];
    if (config.showAttributes) {
      cells.push(
        el('td', { text: item.ss }),
        el('td', { text: String(item.avg_time) }),
        el('td', { text: item.candidate ? 'Yes' : 'No' })
      );
    }
    return el('tr', {}, cells);
  }

  function columnCount(config) {
    return config.showAttributes ? 10 : 7;
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
      match: itemMatchesSearch,
      compare: compareItems,
      sorts: restoreSorts(headers, keys),
      row: function (item) {
        return renderRow(item, config);
      },
      columnCount: columnCount(config),
      pageLength: config.pageLength || 100,
      emptyMessage: 'No items yet.',
      noMatchMessage: 'No items match.',
      status: 'Loading items…',
      onSort: function (sorts) {
        persistSorts(headers, sorts, keys);
      },
    });

    tbody.addEventListener('submit', function (event) {
      var form = event.target.closest('.kept-toggle-form');
      if (!form) {
        return;
      }
      event.preventDefault();
      var cell = form.closest('td.kept');
      var button = form.querySelector('.kept-toggle-btn');
      var valueInput = form.querySelector('input[name="value"]');
      var artifactId = Number((cell && cell.dataset.artifactId) || 0);
      button.disabled = true;
      fetch(form.action, {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: new FormData(form),
      })
        .then(function (response) {
          return response.json().then(function (data) {
            return { ok: response.ok, data: data };
          });
        })
        .then(function (result) {
          if (result.ok && result.data && result.data.ok) {
            var isKept = result.data.is_kept === 1;
            var item = items.find(function (row) {
              return row.id === artifactId;
            });
            if (item) {
              item.is_kept = isKept;
            }
            cell.dataset.kept = isKept ? '1' : '0';
            button.textContent = isKept ? 'Kept' : 'Keep';
            button.setAttribute('aria-pressed', isKept ? 'true' : 'false');
            valueInput.value = isKept ? '0' : '1';
            showToast(toastEl, result.data.message || 'Updated.', 'success');
          } else {
            showToast(toastEl, (result.data && result.data.message) || 'Request failed', 'error');
          }
          button.disabled = false;
        })
        .catch(function (error) {
          showToast(toastEl, 'Network error: ' + error.message, 'error');
          button.disabled = false;
        });
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();

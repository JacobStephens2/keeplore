(function () {
  var PAGE_LENGTH = 100;
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
    var el = document.getElementById('items-list-config');
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (err) {
      console.error('[ItemsList] invalid config', err);
      return null;
    }
  }

  function itemMatchesSearch(item, query) {
    var q = String(query || '').trim().toLowerCase();
    if (!q) {
      return true;
    }
    var haystack = [
      item.title || '',
      item.type || '',
      (item.tags || []).join(' '),
    ].join(' ').toLowerCase();
    return q.split(/\s+/).every(function (part) {
      return haystack.indexOf(part) !== -1;
    });
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
    if (av < bv) {
      return dir === 'desc' ? 1 : -1;
    }
    if (av > bv) {
      return dir === 'desc' ? -1 : 1;
    }
    return 0;
  }

  function sortItems(items, sorts) {
    var copy = items.slice();
    copy.sort(function (a, b) {
      for (var i = 0; i < sorts.length; i++) {
        var result = compareItems(a, b, sorts[i].key, sorts[i].dir);
        if (result !== 0) {
          return result;
        }
      }
      return a.id - b.id;
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
      el('td', { text: item.type }),
      el('td', { text: (item.tags || []).join(', ') }),
      el('td', { className: 'artifact_title' }, [
        el('a', {
          className: 'table-action',
          href: config.itemUrlPrefix + encodeURIComponent(item.id),
          text: item.title,
        }),
      ]),
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

  function statusRow(config, message) {
    return el('tr', { className: 'items-list-status' }, [
      el('td', { colspan: String(columnCount(config)), text: message }),
    ]);
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
    if (!config || !search || !tbody || !table) {
      return;
    }

    var keys = columnKeys(table);
    var headers = headerTexts(table);
    var state = {
      items: [],
      loaded: false,
      sorts: restoreSorts(headers, keys),
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

    function filteredItems() {
      var query = search.value;
      var matched = state.items.filter(function (item) {
        return itemMatchesSearch(item, query);
      });
      return sortItems(matched, state.sorts);
    }

    function render() {
      tbody.textContent = '';
      if (!state.loaded) {
        tbody.appendChild(statusRow(config, 'Loading items…'));
        if (pager) {
          pager.textContent = '';
        }
        return;
      }
      var rows = filteredItems();
      var total = state.items.length;
      var shown = rows.length;
      if (nameHeader) {
        nameHeader.textContent = search.value.trim()
          ? 'Name (' + shown + ' of ' + total + ')'
          : 'Name (' + total + ')';
      }
      if (rows.length === 0) {
        tbody.appendChild(statusRow(
          config,
          search.value.trim() ? 'No items match.' : 'No items yet.'
        ));
        if (pager) {
          pager.textContent = '';
        }
        return;
      }
      var pageLength = config.pageLength || PAGE_LENGTH;
      var pageCount = Math.max(1, Math.ceil(rows.length / pageLength));
      if (state.page >= pageCount) {
        state.page = pageCount - 1;
      }
      if (state.page < 0) {
        state.page = 0;
      }
      var start = state.page * pageLength;
      var pageRows = rows.slice(start, start + pageLength);
      var fragment = document.createDocumentFragment();
      pageRows.forEach(function (item) {
        fragment.appendChild(renderRow(item, config));
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
      if (state.loaded) {
        render();
      }
    });

    table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        var current = state.sorts[0];
        var dir = current && current.key === key && current.dir === 'desc' ? 'asc' : 'desc';
        state.sorts = [{ key: key, dir: dir }];
        applyAriaSort();
        persistSorts(headers, state.sorts, keys);
        render();
      });
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
            var item = state.items.find(function (row) {
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

    render();

    fetch(config.dataUrl, { credentials: 'include', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Request failed: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        state.items = Array.isArray(data.items) ? data.items : [];
        state.loaded = true;
        state.page = 0;
        render();
      })
      .catch(function (error) {
        tbody.textContent = '';
        tbody.appendChild(statusRow(config, 'Could not load items: ' + error.message));
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();

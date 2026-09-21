// Create Item adapter for KeeploreListTable. Search lives in the page HTML
// so Name can keep autofocus. Empty queries match nothing so the create form
// is not buried under the whole list. Kept toggles through set-tracked.php.
(function (root, factory) {
  var listTable = root.KeeploreListTable;
  if (!listTable && typeof require === 'function') {
    listTable = require('./list-table.js');
  }
  var api = factory(listTable);
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.KeeploreCreateItemLookup = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (ListTable) {
  'use strict';

  function itemMatchesSearch(item, query) {
    if (!String(query || '').trim()) {
      return false;
    }
    return ListTable.fieldsMatchSearch([item.title || ''], query);
  }

  function compareItems(a, b, key, dir) {
    var av = a[key];
    var bv = b[key];
    if (key === 'is_kept') {
      av = av ? 1 : 0;
      bv = bv ? 1 : 0;
    } else {
      av = String(av || '').toLowerCase();
      bv = String(bv || '').toLowerCase();
    }
    return ListTable.compareValues(av, bv, dir);
  }

  function sortItems(items, sort) {
    return ListTable.sortRecords(items, sort ? [sort] : [], compareItems);
  }

  function configFromPage(doc) {
    var node = doc.getElementById('create-item-lookup-config');
    if (!node) {
      return null;
    }
    try {
      return JSON.parse(node.textContent);
    } catch (err) {
      console.error('[CreateItemLookup] invalid config', err);
      return null;
    }
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

  function renderKeptCell(item, config) {
    var el = ListTable.el;
    var cell = el('td', {
      className: 'kept',
      dataset: { artifactId: String(item.id), kept: item.is_kept ? '1' : '0' },
    });
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
      el('input', { type: 'hidden', name: 'return_to', value: config.return_to || 'new' }),
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
    var el = ListTable.el;
    return el('tr', {}, [
      renderKeptCell(item, config),
      el('td', { className: 'artifact_title' }, [
        el('a', {
          className: 'table-action',
          href: config.itemUrlPrefix + encodeURIComponent(item.id),
          text: item.title,
        }),
      ]),
    ]);
  }

  function bind(doc, fetchImpl) {
    var config = configFromPage(doc);
    var search = doc.getElementById('create-item-lookup-search');
    var tbody = doc.getElementById('create-item-lookup-body');
    var table = doc.getElementById('create-item-lookup');
    var nameHeader = doc.getElementById('create-item-lookup-name-header');
    var pager = doc.getElementById('create-item-lookup-pager');
    var toastEl = doc.getElementById('create-item-lookup-toast');
    var request = fetchImpl || fetch;
    if (search) {
      search.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          search.blur();
        }
      });
    }
    if (!config || !search || !tbody || !table || !ListTable) {
      return;
    }

    var items = [];
    var list = ListTable.mount({
      document: doc,
      search: search,
      table: table,
      tbody: tbody,
      nameHeader: nameHeader,
      pager: pager,
      match: itemMatchesSearch,
      compare: compareItems,
      sorts: [{ key: 'title', dir: 'asc' }],
      row: function (item) {
        return renderRow(item, config);
      },
      columnCount: 2,
      pageLength: config.pageLength || 10,
      emptyMessage: 'Type a name to see if this item is already on your account.',
      noMatchMessage: 'No items match.',
      status: 'Loading items…',
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
      if (button) {
        button.disabled = true;
      }
      request(form.action, {
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
            if (cell) {
              cell.dataset.kept = isKept ? '1' : '0';
            }
            if (button) {
              button.textContent = isKept ? 'Kept' : 'Keep';
              button.setAttribute('aria-pressed', isKept ? 'true' : 'false');
            }
            if (valueInput) {
              valueInput.value = isKept ? '0' : '1';
            }
            showToast(toastEl, result.data.message || 'Updated.', 'success');
          } else {
            showToast(toastEl, (result.data && result.data.message) || 'Request failed', 'error');
          }
          if (button) {
            button.disabled = false;
          }
        })
        .catch(function (error) {
          showToast(toastEl, 'Network error: ' + error.message, 'error');
          if (button) {
            button.disabled = false;
          }
        });
    });

    request(config.dataUrl, { credentials: 'include', headers: { Accept: 'application/json' } })
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
      document.addEventListener('DOMContentLoaded', function () {
        bind(document);
      });
    } else {
      bind(document);
    }
  }

  return {
    itemMatchesSearch: itemMatchesSearch,
    sortItems: sortItems,
    bind: bind,
  };
}));

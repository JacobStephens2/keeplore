// Item search, the ten-result list, and the exact-match id.
//
// Record Use passes the collection-list address and fetch. Edit User and
// Item chosen instead pass the items already on the page. The module does
// not name a host. A pick or select writes the id and the name. Typed
// text keeps what was typed and sets the id only when a label matches.
// hide closes the list without clearing it; an empty query clears it.
// Enter and Space on a result stop propagation so Enter in the search
// field can still submit the form.

(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.ItemPick = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var MAX_RESULTS = 10;

  function sendToLogin() {
    if (typeof globalThis !== 'undefined' && globalThis.location) {
      globalThis.location.href = '/login.php';
    }
  }

  function labelOf(item) {
    if (item && typeof item.label === 'string') return item.label;
    if (item && typeof item.Title === 'string') return item.Title;
    return '';
  }

  function normalize(item) {
    return { id: item ? item.id : '', label: labelOf(item) };
  }

  function substringMatches(items, needle) {
    var matched = [];
    var lowered = needle.toLowerCase();
    for (var i = 0; i < items.length; i++) {
      var choice = normalize(items[i]);
      if (choice.label.toLowerCase().indexOf(lowered) !== -1) matched.push(choice);
    }
    return matched;
  }

  function exactMatch(items, typed) {
    var needle = String(typed == null ? '' : typed).trim().toLowerCase();
    if (needle === '') return null;
    for (var i = 0; i < items.length; i++) {
      if (String(items[i].label).toLowerCase() === needle) return items[i];
    }
    return null;
  }

  function bind(options) {
    var doc = options.document || document;
    var input = options.input;
    var idInput = options.idInput;
    var list = options.list;
    var fetchFn = options.fetch || function (url, init) { return fetch(url, init); };
    var setOpen = options.setOpen || function () {};
    var catalog = Array.isArray(options.items) ? options.items : null;
    var latest = 0;
    // Escape returns focus to the search. That focus must not reopen the list.
    var ignoreFocus = false;

    list.replaceChildren();

    function hide() {
      setOpen(false);
    }

    function writeId(id) {
      idInput.value = id == null || id === '' ? '' : String(id);
    }

    function focusSearch() {
      ignoreFocus = true;
      try {
        if (typeof input.focus === 'function') input.focus();
      } finally {
        ignoreFocus = false;
      }
    }

    function render(items) {
      list.replaceChildren();
      items.slice(0, MAX_RESULTS).forEach(function (choice) {
        var li = doc.createElement('li');
        li.tabIndex = 0;
        li.textContent = choice.label;
        li.addEventListener('click', function () {
          commit(choice);
        });
        li.addEventListener('keydown', function (event) {
          if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            hide();
            focusSearch();
            return;
          }
          if (event.key !== 'Enter' && event.key !== ' ') return;
          event.preventDefault();
          event.stopPropagation();
          commit(choice);
        });
        list.append(li);
      });
      setOpen(list.children.length > 0);
    }

    // A pick or select is newer than a search already in flight.
    function commit(choice) {
      latest += 1;
      writeId(choice.id);
      input.value = choice.label == null ? '' : String(choice.label);
      hide();
    }

    function showMatches(thisSearch, items, typed) {
      if (thisSearch !== latest) return;
      var normalized = [];
      for (var i = 0; i < items.length; i++) normalized.push(normalize(items[i]));
      render(normalized);
      var match = exactMatch(normalized, typed);
      writeId(match ? match.id : '');
    }

    function fail(thisSearch) {
      if (thisSearch !== latest) return;
      list.replaceChildren();
      hide();
    }

    function run(query) {
      var thisSearch = ++latest;
      var trimmed = String(query == null ? '' : query).trim();
      writeId('');
      if (trimmed === '') {
        list.replaceChildren();
        hide();
        return Promise.resolve();
      }
      if (catalog) {
        showMatches(thisSearch, substringMatches(catalog, trimmed), query);
        return Promise.resolve();
      }
      var pending;
      try {
        // 200 is the collection list's own maximum page.
        pending = fetchFn(options.url, {
          method: 'POST',
          credentials: 'include',
          body: JSON.stringify({ query: trimmed, per_page: 200 }),
        });
      } catch (error) {
        fail(thisSearch);
        return Promise.resolve();
      }
      return Promise.resolve(pending)
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (thisSearch !== latest) return;
          data = data || {};
          if (data.authenticated === false) {
            sendToLogin();
            list.replaceChildren();
            hide();
            return;
          }
          showMatches(thisSearch, data.artifacts || [], query);
        })
        .catch(function () {
          fail(thisSearch);
        });
    }

    input.addEventListener('input', function () {
      return run(input.value);
    });

    input.addEventListener('focus', function () {
      if (ignoreFocus) return;
      if (list.children.length > 0) setOpen(true);
    });

    if (typeof doc.addEventListener === 'function') {
      doc.addEventListener('click', function (event) {
        var target = event && event.target;
        if (target === input) return;
        if (target && typeof list.contains === 'function' && list.contains(target)) return;
        hide();
      });
    }

    return {
      hide: hide,
      select: function (id, name) {
        commit({ id: id, label: name });
      },
    };
  }

  return { bind: bind };
}));

// Person search, the ten-result list, and inline create.
//
// Record Use and Quick record each pass the search address, the create
// address, and fetch. The module does not name a host. hide closes the
// list without clearing it; an empty query clears it. Enter and Space on
// a result stop propagation so the popup can still save on Enter and
// close on Escape everywhere else.

(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  root.PersonPick = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var MAX_RESULTS = 10;

  function personName(person) {
    if (person && typeof person.FullName === 'string' && person.FullName.length > 0) {
      return person.FullName;
    }
    var first = person && person.FirstName ? String(person.FirstName) : '';
    var last = person && person.LastName ? String(person.LastName) : '';
    return (first + ' ' + last).trim();
  }

  function renderResults(doc, list, people, hooks) {
    list.replaceChildren();
    (people || []).slice(0, MAX_RESULTS).forEach(function (person) {
      var li = doc.createElement('li');
      var choice = { id: person.id, name: personName(person) };
      li.tabIndex = 0;
      li.textContent = choice.name;
      li.addEventListener('click', function () {
        hooks.onPick(choice);
      });
      li.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          hooks.onEscape();
          return;
        }
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        event.stopPropagation();
        hooks.onPick(choice);
      });
      list.append(li);
    });
  }

  // A signed-out search is authenticated: false. The string "false" is
  // not that response, and neither is 0.
  function sendToLogin() {
    if (typeof globalThis !== 'undefined' && globalThis.location) {
      globalThis.location.href = '/login.php';
    }
  }

  function bindSearch(options) {
    var doc = options.document || document;
    var input = options.input;
    var list = options.list;
    var fetchFn = options.fetch || function (url, init) { return fetch(url, init); };
    var onPick = options.onPick || function () {};
    var onEscape = options.onEscape || function () {};
    var setOpen = options.setOpen || function () {};
    var latest = 0;

    function hide() {
      setOpen(false);
    }

    function showPeople(people) {
      renderResults(doc, list, people, { onPick: onPick, onEscape: onEscape });
      setOpen(list.children.length > 0);
    }

    function run(query) {
      var thisSearch = ++latest;
      var trimmed = String(query == null ? '' : query).trim();
      if (trimmed === '') {
        list.replaceChildren();
        hide();
        return Promise.resolve();
      }
      var pending;
      try {
        pending = fetchFn(options.url, {
          method: 'POST',
          credentials: 'include',
          body: JSON.stringify({ query: trimmed, userid: options.userid }),
        });
      } catch (error) {
        list.replaceChildren();
        hide();
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
          showPeople(data.users || []);
        })
        .catch(function () {
          if (thisSearch !== latest) return;
          list.replaceChildren();
          hide();
        });
    }

    input.addEventListener('input', function () {
      run(input.value);
    });

    return { hide: hide, search: run };
  }

  function createPerson(options) {
    var first = String(options.first.value || '').trim();
    var last = String(options.last.value || '').trim();
    var button = options.button;
    var message = options.message;
    if (first === '' && last === '') {
      message.textContent = 'Enter a name.';
      return Promise.resolve();
    }
    button.disabled = true;
    message.textContent = 'Creating…';
    var fetchFn = options.fetch || function (url, init) { return fetch(url, init); };
    var body = new FormData();
    body.append('FirstName', first);
    body.append('LastName', last);
    var token = typeof options.token === 'function' ? options.token() : '';
    if (typeof token === 'string' && token !== '') {
      body.append('csrf_token', token);
    }
    var pending;
    try {
      pending = fetchFn(options.url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: body,
      });
    } catch (error) {
      button.disabled = false;
      message.textContent = 'Error: ' + error.message;
      return Promise.resolve();
    }
    return Promise.resolve(pending)
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        button.disabled = false;
        if (result.ok && result.data && result.data.ok) {
          if (typeof options.onSuccess === 'function') {
            options.onSuccess(result.data.id, result.data.FullName);
          }
          return;
        }
        message.textContent = (result.data && result.data.message) || 'Could not create person.';
      })
      .catch(function (error) {
        button.disabled = false;
        message.textContent = 'Error: ' + error.message;
      });
  }

  return {
    bindSearch: bindSearch,
    createPerson: createPerson,
  };
}));

// Quick record popup: record a use without leaving the page.
//
// init(root, options) drives private/shared/quick_record_popup.php and
// returns open(itemId, itemName, context). The page passes its toast and
// onRecorded(data, context), which handles the page's own row; context is
// whatever the page passed to open. Callers pass fetch and document so
// tests can supply them. The date starts as the popup's data-app-day
// attribute, a YYYY-MM-DD string rendered with the page.

(function () {
  var RecordUseSubmit = typeof window !== 'undefined' && window.RecordUseSubmit
    ? window.RecordUseSubmit
    : (typeof require === 'function' ? require('./record-use-submit.js') : null);
  var PersonPick = typeof window !== 'undefined' && window.PersonPick
    ? window.PersonPick
    : (typeof require === 'function' ? require('./person-pick.js') : null);

  function init(root, options) {
    options = options || {};
    var doc = options.document || document;
    var fetchFn = options.fetch || fetch;
    var toast = options.toast;
    var onRecorded = options.onRecorded || function () {};

    function byId(id) { return root.querySelector('#' + id); }
    var form = byId('record-modal-form');
    var itemIdInput = byId('record-modal-artifact-id');
    var itemNameInput = byId('record-modal-artifact-name');
    var subtitle = byId('record-modal-artifact');
    var dateInput = byId('record-modal-date');
    var notesInput = byId('record-modal-notes');
    var saveBtn = form.querySelector('.modal-save');
    var fullFormLink = byId('record-modal-fullform-link');
    var usersWrap = byId('record-modal-users');
    var ownerPersonId = String(byId('record-modal-owner-id').value);
    var searchWrap = byId('record-modal-user-search-wrap');
    var search = byId('record-modal-user-search');
    var results = byId('record-modal-user-results');
    var newToggle = byId('record-modal-new-user-toggle');
    var newForm = byId('record-modal-new-user-form');
    var newFirst = byId('record-modal-new-first');
    var newLast = byId('record-modal-new-last');
    var newCreate = byId('record-modal-new-create');
    var newCancel = byId('record-modal-new-cancel');
    var newMsg = byId('record-modal-new-msg');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var peopleSearchUrl = root.getAttribute('data-people-search-url');
    var newPersonUrl = root.getAttribute('data-new-person-url');
    var ownerUserId = root.getAttribute('data-user-id');
    // A missing day, or one that is not YYYY-MM-DD, stays blank. The field
    // is required, so the use is not recorded on a browser-local day. A real
    // calendar day is the App day module's check, which rendered the attribute.
    var appDay = root.getAttribute('data-app-day') || '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(appDay)) appDay = '';

    // People added beyond the owner, as { id, chip }. The owner is user[0].
    var added = [];
    var nextIndex = 1;
    var context = null;

    function hideResults() {
      results.innerHTML = '';
      results.hidden = true;
    }

    var peopleSearch;

    function hasPerson(id) {
      if (String(id) === ownerPersonId) return true;
      return added.some(function (person) { return person.id === String(id); });
    }

    function addPerson(id, name) {
      if (id && hasPerson(id)) return;
      var i = nextIndex++;
      var chip = doc.createElement('div');
      chip.className = 'modal-user-chip';

      var label = doc.createElement('span');
      label.className = 'modal-user-name';
      label.textContent = name;

      var idInput = doc.createElement('input');
      idInput.type = 'hidden';
      idInput.name = 'user[' + i + '][id]';
      idInput.value = String(id);

      var nameInput = doc.createElement('input');
      nameInput.type = 'hidden';
      nameInput.name = 'user[' + i + '][name]';
      nameInput.value = name;

      var person = { id: String(id), chip: chip };
      var remove = doc.createElement('button');
      remove.type = 'button';
      remove.className = 'modal-user-remove';
      remove.setAttribute('aria-label', 'Remove ' + name);
      remove.textContent = '×';
      remove.addEventListener('click', function () {
        chip.remove();
        added = added.filter(function (p) { return p !== person; });
      });

      chip.append(label, idInput, nameInput, remove);
      usersWrap.appendChild(chip);
      added.push(person);
    }

    function resetNewPersonForm() {
      newFirst.value = '';
      newLast.value = '';
      newMsg.textContent = '';
      newForm.style.display = 'none';
      newToggle.style.display = '';
    }

    function resetPeople() {
      added.forEach(function (person) { person.chip.remove(); });
      added = [];
      nextIndex = 1;
      search.value = '';
      hideResults();
      resetNewPersonForm();
    }

    function open(itemId, itemName, pageContext) {
      context = pageContext;
      itemIdInput.value = itemId;
      itemNameInput.value = itemName;
      subtitle.textContent = itemName;
      dateInput.value = appDay;
      notesInput.value = '';
      resetPeople();
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save';
      fullFormLink.href = form.getAttribute('action') + '?artifact_id=' + encodeURIComponent(itemId);
      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      doc.body.classList.add('modal-open');
      setTimeout(function () { dateInput.focus(); }, 30);
    }

    function close() {
      root.hidden = true;
      root.setAttribute('aria-hidden', 'true');
      doc.body.classList.remove('modal-open');
      context = null;
    }

    root.querySelectorAll('[data-modal-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !root.hidden) close();
    });

    peopleSearch = PersonPick.bindSearch({
      document: doc,
      input: search,
      list: results,
      url: peopleSearchUrl,
      userid: ownerUserId,
      fetch: fetchFn,
      onPick: function (person) {
        addPerson(person.id, person.name);
        search.value = '';
        peopleSearch.hide();
        search.focus();
      },
      onEscape: function () {
        peopleSearch.hide();
        search.focus();
      },
      setOpen: function (open) { results.hidden = !open; },
    });

    // Tapping outside the search box dismisses the results so the
    // "+ New person" button underneath becomes tappable.
    doc.addEventListener('pointerdown', function (event) {
      if (results.hidden) return;
      if (!searchWrap.contains(event.target)) peopleSearch.hide();
    });

    function createPerson() {
      PersonPick.createPerson({
        url: newPersonUrl,
        first: newFirst,
        last: newLast,
        button: newCreate,
        message: newMsg,
        token: function () { return csrfInput ? csrfInput.value : ''; },
        fetch: fetchFn,
        onSuccess: function (id, fullName) {
          addPerson(id, fullName);
          resetNewPersonForm();
        },
      });
    }

    newToggle.addEventListener('click', function () {
      newForm.style.display = 'flex';
      newToggle.style.display = 'none';
      newFirst.focus();
    });
    newCancel.addEventListener('click', resetNewPersonForm);
    newCreate.addEventListener('click', createPerson);
    [newFirst, newLast].forEach(function (input) {
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); createPerson(); }
      });
    });

    // A save reports the context it was submitted with, even if the popup
    // was closed or reopened for another item while it was in flight.
    var savingContext = null;
    form.addEventListener('submit', function () { savingContext = context; });
    RecordUseSubmit.bind(form, {
      fetch: fetchFn,
      toast: toast,
      saveButton: saveBtn,
      saveLabel: 'Save',
      onSuccess: function (data) {
        var saved = savingContext;
        onRecorded(data, saved);
        if (!root.hidden && context === saved) close();
      }
    });

    // Enter saves, except in notes (newline), in the new-person form (which
    // creates the person), or in the people search while results are open
    // (which picks the top result).
    form.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      var target = event.target;
      if (!target || target.tagName === 'TEXTAREA') return;
      if (newForm.contains(target)) return;
      if (target === search && !results.hidden && results.firstElementChild) {
        event.preventDefault();
        results.firstElementChild.click();
        return;
      }
      event.preventDefault();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      }
    });

    return { open: open };
  }

  var QuickRecord = { init: init };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = QuickRecord;
  }
  if (typeof window !== 'undefined') {
    window.QuickRecord = QuickRecord;
  }
})();

// Record Use page behaviour.
//
// bindEnter: Enter in a field submits the form. requestSubmit runs the
// browser's constraint checks (Number of uses must be 1-20); form.submit()
// would post past them. Enter in Notes is a new line and Enter on a
// focused button presses it, so those are left to the browser.
//
// keepValueOnWheel: a mouse wheel over a focused number input changes its
// value. Nobody scrolls Number of uses on purpose, so the wheel blurs it
// and scrolls the page instead.
//
// Callers pass the document and elements so tests can observe them
// without a browser.
(function () {
  var BUTTON_INPUT_TYPES = ['button', 'submit'];

  function keepsOwnEnter(target) {
    if (!target) return false;
    var tag = String(target.tagName || '').toUpperCase();
    if (tag === 'TEXTAREA' || tag === 'BUTTON') return true;
    return tag === 'INPUT' &&
      BUTTON_INPUT_TYPES.indexOf(String(target.type || '').toLowerCase()) !== -1;
  }

  var RecordNew = {
    bindEnter: function (doc, form) {
      if (!form) return;

      doc.addEventListener('keypress', function (event) {
        if (event.key !== 'Enter') return;
        if (keepsOwnEnter(event.target)) return;
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    },

    keepValueOnWheel: function (doc, input) {
      if (!input) return;

      input.addEventListener('wheel', function () {
        if (doc.activeElement === input) input.blur();
      }, { passive: true });
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = RecordNew;
  } else if (typeof document !== 'undefined') {
    document.querySelector('#SearchTitles').focus();
    RecordNew.bindEnter(document, document.querySelector('main form'));
    RecordNew.keepValueOnWheel(document, document.querySelector('#useCount'));
  }
})();

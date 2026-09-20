// Keyboard shortcut:
//   s - submit the form marked data-shortcut="save"
//
// Bind is a no-op without a form. Callers pass the form so tests can
// observe requestSubmit without a browser.
(function () {
  function isTextualInput(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    var tag = (el.tagName || '').toUpperCase();
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag !== 'INPUT') return false;
    var type = (el.type || 'text').toLowerCase();
    return type !== 'checkbox' && type !== 'radio' && type !== 'button'
      && type !== 'submit';
  }

  var FormSaveShortcut = {
    bind: function (doc, form) {
      if (!form) return;

      doc.addEventListener('keydown', function (event) {
        if (event.metaKey || event.ctrlKey || event.altKey) return;
        if (event.key !== 's' && event.key !== 'S') return;
        if (isTextualInput(event.target)) return;
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else if (typeof form.submit === 'function') {
          form.submit();
        }
      });
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = FormSaveShortcut;
  } else if (typeof document !== 'undefined') {
    FormSaveShortcut.bind(document, document.querySelector('[data-shortcut="save"]'));
  }
})();

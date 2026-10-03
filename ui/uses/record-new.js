// Record Use: Enter anywhere submits the form.
//
// requestSubmit runs the browser's constraint checks (Number of uses must
// be 1-20); form.submit() would post past them. Callers pass the form so
// tests can observe the submit without a browser.
(function () {
  var RecordNewEnter = {
    bind: function (doc, form) {
      if (!form) return;

      doc.addEventListener('keypress', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = RecordNewEnter;
  } else if (typeof document !== 'undefined') {
    document.querySelector('#SearchTitles').focus();
    RecordNewEnter.bind(document, document.querySelector('main form'));
  }
})();

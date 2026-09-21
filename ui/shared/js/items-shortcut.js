// Keyboard shortcuts:
//   i - open the Items page (nav link marked data-shortcut="items")
//   s - focus the page search box (input marked data-shortcut="search"
//       or data-shortcut="items-search")
//   f - toggle the filter panel (button#display_filters, via the existing click handler)
//
// Bind is a no-op on pages with none of those targets (public landing / login).
// Callers pass go(url) so tests can observe navigation without a browser.
(function () {
  function isTextualInput(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    var tag = (el.tagName || '').toUpperCase();
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag !== 'INPUT') return false;
    var type = (el.type || 'text').toLowerCase();
    return type !== 'checkbox' && type !== 'radio' && type !== 'button'
      && type !== 'submit' && type !== 'reset' && type !== 'file'
      && type !== 'range' && type !== 'color' && type !== 'image'
      && type !== 'hidden';
  }

  var ItemsShortcut = {
    bind: function (doc, go) {
      var link = doc.querySelector('[data-shortcut="items"]');
      var search = doc.querySelector('[data-shortcut="search"], [data-shortcut="items-search"]');
      var filtersButton = doc.querySelector('#display_filters');
      var url = link ? link.getAttribute('href') : '';
      if (!url && !search && !filtersButton) return;

      doc.addEventListener('keydown', function (event) {
        if (event.metaKey || event.ctrlKey || event.altKey) return;
        if (isTextualInput(event.target)) return;

        if ((event.key === 's' || event.key === 'S') && search) {
          if (typeof event.preventDefault === 'function') event.preventDefault();
          if (typeof search.focus === 'function') search.focus();
          if (typeof search.select === 'function') search.select();
          return;
        }

        if ((event.key === 'f' || event.key === 'F') && filtersButton) {
          if (typeof event.preventDefault === 'function') event.preventDefault();
          if (typeof filtersButton.click === 'function') filtersButton.click();
          return;
        }

        if ((event.key === 'i' || event.key === 'I') && url) {
          if (typeof event.preventDefault === 'function') event.preventDefault();
          go(url);
        }
      });
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = ItemsShortcut;
  } else if (typeof document !== 'undefined') {
    ItemsShortcut.bind(document, function (url) {
      window.location.href = url;
    });
  }
})();

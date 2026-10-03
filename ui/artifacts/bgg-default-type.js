// Create Item: using a BoardGameGeek match sets Type to the owner's BGG
// default from Settings (data-default-type-id/-name on Request BGG Data),
// unless the owner already changed Type on this form. Returns whether it
// changed Type. Callers pass the document and button so tests can run it
// without a browser.
(function () {
  var BggDefaultType = {
    apply: function (doc, button, initialTypeId) {
      var id = button && button.dataset ? button.dataset.defaultTypeId : '';
      var typeInput = doc.getElementById('type');
      var typeSearch = doc.getElementById('type_search');
      if (!id || !typeInput || typeInput.value !== initialTypeId) return false;
      typeInput.value = id;
      if (typeSearch) typeSearch.value = button.dataset.defaultTypeName || '';
      return true;
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = BggDefaultType;
  } else {
    window.BggDefaultType = BggDefaultType;
  }
})();

// Create Item: using a BoardGameGeek match sets Type to the owner's Type for
// BoardGameGeek items from Settings (data-default-type-id/-name on Request
// BGG Data), unless the owner already picked a Type other than Create Item's
// own default (data-form-default-type-id). A blank Type, left by search text
// that matches no type, counts as not picked. Returns whether it changed
// Type. Callers pass the document and button so tests can run it without
// a browser.
(function () {
  var BggDefaultType = {
    apply: function (doc, button) {
      var data = (button && button.dataset) || {};
      var id = data.defaultTypeId;
      var typeInput = doc.getElementById('type');
      var typeSearch = doc.getElementById('type_search');
      if (!id || !typeInput) return false;
      if (typeInput.value !== '' && typeInput.value !== data.formDefaultTypeId) return false;
      typeInput.value = id;
      if (typeSearch) typeSearch.value = data.defaultTypeName || '';
      return true;
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = BggDefaultType;
  } else {
    window.BggDefaultType = BggDefaultType;
  }
})();

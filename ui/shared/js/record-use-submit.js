// Record a use from a page form without leaving.
//
// Bind posts the form as AJAX (X-Requested-With), toasts, and calls
// onSuccess. Callers pass fetch so tests can observe the request.

(function () {
  function toastFor(toastEl) {
    var timer = null;
    return function showToast(message, kind) {
      if (!toastEl) {
        if (typeof alert === 'function') alert(message);
        return;
      }
      toastEl.textContent = message;
      toastEl.classList.remove('toast-success', 'toast-error', 'is-visible');
      toastEl.classList.add(kind === 'error' ? 'toast-error' : 'toast-success');
      void toastEl.offsetWidth;
      toastEl.classList.add('is-visible');
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        toastEl.classList.remove('is-visible');
      }, 3500);
    };
  }

  var RecordUseSubmit = {
    toast: toastFor,
    bind: function (form, options) {
      if (!form) return;
      options = options || {};
      var fetchFn = options.fetch || fetch;
      var toast = options.toast || toastFor(options.toastEl || null);
      var saveBtn = options.saveButton || form.querySelector('[type="submit"]');
      var saveLabel = options.saveLabel || (saveBtn && saveBtn.textContent) || 'Save';
      var pendingLabel = options.pendingLabel || 'Saving…';
      var artifactIdInput = options.artifactIdInput || null;
      var onSuccess = options.onSuccess || function () {};
      var missingItemMessage = options.missingItemMessage || 'Please choose an item.';

      form.addEventListener('submit', function (event) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (artifactIdInput && !artifactIdInput.value) {
          toast(missingItemMessage, 'error');
          if (typeof options.onInvalid === 'function') options.onInvalid();
          return;
        }
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.textContent = pendingLabel;
        }
        function restore() {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = saveLabel;
          }
        }
        var body = Object.prototype.hasOwnProperty.call(options, 'body')
          ? options.body
          : (typeof FormData !== 'undefined' ? new FormData(form) : undefined);
        return fetchFn(form.action, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          body: body,
        })
          .then(function (response) {
            return response.json().then(function (data) {
              return { ok: response.ok, data: data };
            });
          })
          .then(function (result) {
            if (result.ok && result.data && result.data.ok) {
              onSuccess(result.data);
              toast(result.data.message || 'Interaction recorded.', 'success');
            } else {
              toast(
                (result.data && result.data.message) || 'Could not record the use.',
                'error'
              );
            }
            restore();
          })
          .catch(function (error) {
            toast('Network error: ' + error.message, 'error');
            restore();
          });
      });
    }
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = RecordUseSubmit;
  }
  if (typeof window !== 'undefined') {
    window.RecordUseSubmit = RecordUseSubmit;
  }
})();

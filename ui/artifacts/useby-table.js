(function () {
  var tableElement = document.getElementById('useBy');
  let table = new DataTable(tableElement, {
    order: JSON.parse(tableElement.getAttribute('data-order')),
  });

  // Pressing Enter inside the filter panel re-applies the filters (a normal
  // server-side submit/reload). Scoped to the filter form so Enter elsewhere
  // on the page — the table search box, the record-interaction modal — never
  // triggers a stray submit or reload. (The modal handles its own Enter.)
  var usebyFilterForm = document.getElementById('useby-filters');
  if (usebyFilterForm) {
    usebyFilterForm.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      if (event.target && event.target.tagName === 'TEXTAREA') return;
      event.preventDefault();
      if (typeof usebyFilterForm.requestSubmit === 'function') {
        usebyFilterForm.requestSubmit();
      } else {
        usebyFilterForm.submit();
      }
    });
  }

  (function () {
    var showToast = window.RecordUseSubmit
      ? RecordUseSubmit.toast(document.getElementById('useby-toast'))
      : function (message) { alert(message); };

    var overdueSpan = document.querySelector('span#totalOverdue');

    var recordPopup = document.getElementById('record-modal');
    var quickRecord = recordPopup ? QuickRecord.init(recordPopup, {
      toast: showToast,
      onRecorded: handleRecorded
    }) : null;

    // DataTable detaches other pages' rows and brings them back on redraw.
    // Listen on the table so every visible Record link opens the popup.
    document.getElementById('useBy').addEventListener('click', function (event) {
      var link = event.target.closest('td.record a');
      if (!link || !quickRecord) return;
      var idMatch = (link.getAttribute('href') || '').match(/artifact_id=(\d+)/);
      if (!idMatch) return;
      event.preventDefault();
      var tr = link.closest('tr');
      var titleAnchor = tr.querySelector('td.name a');
      quickRecord.open(idMatch[1], titleAnchor ? titleAnchor.textContent.trim() : '', tr);
    });

    // A row no longer overdue leaves the table and the overdue count;
    // otherwise it shows its new dates.
    function handleRecorded(data, tr) {
      var overdueCell = tr.querySelector('td.overdue');
      var wasOverdue = overdueCell ? overdueCell.textContent.trim() === 'Yes' : false;

      if (!data.is_overdue) {
        table.row(tr).remove().draw(false);
        if (wasOverdue) decrementOverdueCount();
        return;
      }

      var useByCell = tr.querySelector('td.useByDate');
      if (useByCell && data.new_use_by_date) useByCell.textContent = data.new_use_by_date;
      var recentCell = tr.querySelector('td.mostRecentUse');
      if (recentCell) recentCell.textContent = data.most_recent_use_date || '—';
    }

    function decrementOverdueCount() {
      if (!overdueSpan) return;
      var n = parseInt(overdueSpan.textContent, 10);
      if (!isNaN(n) && n > 0) overdueSpan.textContent = (n - 1);
    }

    function wireRowRemovalForm(form, options) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var btn = form.querySelector('button');
        var originalLabel = btn ? btn.textContent : '';
        var tr = form.closest('tr');
        var wasOverdue = tr && tr.querySelector('td.overdue')
          && tr.querySelector('td.overdue').textContent.trim() === 'Yes';

        if (btn) { btn.disabled = true; btn.textContent = options.pendingLabel || 'Removing…'; }
        fetch(form.action, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          body: new FormData(form),
        })
          .then(function (response) {
            return response.json().then(function (data) {
              return { ok: response.ok, data: data };
            });
          })
          .then(function (result) {
            if (result.ok && result.data && result.data.ok) {
              if (typeof table !== 'undefined' && table && tr) {
                table.row(tr).remove().draw(false);
              } else if (tr) {
                tr.remove();
              }
              if (wasOverdue) decrementOverdueCount();
              showToast(result.data.message || options.successFallback, 'success');
            } else {
              var msg = (result.data && result.data.message) || ('Request failed (HTTP ' + (result.ok ? 'OK' : 'error') + ')');
              showToast(msg, 'error');
              if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
            }
          })
          .catch(function (error) {
            showToast('Network error: ' + error.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
          });
      });
    }

    document.querySelectorAll('table#useBy td.get-rid-of form.get-rid-of-form').forEach(function (form) {
      wireRowRemovalForm(form, { pendingLabel: 'Removing…', successFallback: 'Marked to get rid of.' });
    });

    document.querySelectorAll('table#useBy td.get-rid-of form.untrack-form').forEach(function (form) {
      wireRowRemovalForm(form, { pendingLabel: 'Removing…', successFallback: 'Removed from tracked collection.' });
    });
  })();

  (function () {
    var table = document.querySelector('#useBy');
    var toggle = document.querySelector('#view_toggle');
    if (!table || !toggle) return;
    var segments = toggle.querySelectorAll('.view-toggle-btn');

    var stored = null;
    try { stored = localStorage.getItem('usebyView'); } catch (e) {}
    var initial = stored || (window.innerWidth <= 750 ? 'cards' : 'table');
    applyView(initial, false);

    segments.forEach(function (segment) {
      segment.addEventListener('click', function () {
        var next = segment.dataset.view;
        if (next === currentView()) return;
        try { localStorage.setItem('usebyView', next); } catch (e) {}
        applyView(next, true);
      });
    });

    function currentView() {
      return table.classList.contains('cards-view') ? 'cards' : 'table';
    }

    function applyView(view, animate) {
      if (animate) {
        table.classList.add('view-switching');
        window.setTimeout(function () {
          table.classList.remove('view-switching');
        }, 220);
      }
      if (view === 'cards') {
        table.classList.add('cards-view');
      } else {
        table.classList.remove('cards-view');
      }
      segments.forEach(function (segment) {
        var active = segment.dataset.view === view;
        segment.classList.toggle('is-active', active);
        segment.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
  })();
})();

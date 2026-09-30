// Settings' BoardGameGeek import: while an import is queued or running, poll
// its status every few seconds, then set the button as the server says.
(function () {
  var status = document.getElementById('bgg_import_status');
  var button = document.getElementById('bgg_import_start');
  if (!status || status.dataset.active !== '1') {
    return;
  }
  var misses = 0;

  function poll() {
    fetch(status.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('status ' + response.status);
        }
        // An expired session redirects to the login page, which is not JSON.
        return response.json();
      })
      .then(function (view) {
        misses = 0;
        status.textContent = view.text;
        if (button) {
          button.disabled = !view.can_queue;
        }
        if (view.active) {
          setTimeout(poll, 3000);
        }
      })
      .catch(function () {
        // Ride out a dropped connection, but stop once it looks permanent.
        misses++;
        if (misses < 5) {
          setTimeout(poll, 10000);
        }
      });
  }

  setTimeout(poll, 3000);
})();

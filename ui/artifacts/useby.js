document.querySelector('#send_use_email').addEventListener('click', function(event) {
    var button = event.currentTarget;
    var originalLabel = button.textContent;

    async function getData(userID) {
      var apiOrigin = document.querySelector('meta[name="keeplore-api-origin"]').content;
      const url = `https://${apiOrigin}/send_use_email.php?userID=${userID}`;
      button.disabled = true;
      button.textContent = 'Sending…';
      try {
        const response = await fetch(url, { credentials: 'include' });
        let json = null;
        try { json = await response.json(); } catch (e) { /* non-JSON response */ }
        if (!response.ok) {
          const message = (json && json.message) ? json.message : `Response status: ${response.status}`;
          throw new Error(message);
        }
        console.log(json);
        if (json && json.count_to_notify_about != null) {
          if (json.count_to_notify_about > 0) {
            alert(`Email sent with notification about ${json.count_to_notify_about} artifacts.`);
          } else {
            alert('Nothing to notify about — no email sent.');
          }
        } else {
          alert('Send attempted but the response was not understood.');
        }
      } catch (error) {
        console.error(error.message);
        alert(`Failed to send interact email: ${error.message}`);
      } finally {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    }
    var userID = button.dataset.userid;
    getData(userID);
})

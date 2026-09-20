<?php
  require_once('../../private/initialize.php');
  require_login_or_guest();

  $user_id = $_SESSION['user_id'];
  $stmt = mysqli_prepare($db, "SELECT default_use_interval FROM users WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $user_id);
  mysqli_stmt_execute($stmt);
  $interval_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  $default_interval = (float) ($interval_row['default_use_interval'] ?? 90);

  $artifact_set = find_artifacts_to_get_rid_of();

  $page_title = 'To Get Rid Of';
  include(SHARED_PATH . '/header.php');
?>

<main>
  <header class="page-header">
    <p class="section-label">Letting go</p>
    <h1>To get rid of</h1>
    <p class="page-lede">Items you have decided to release. They no longer appear in your Interact By list.</p>
  </header>

  <?php if ($artifact_set->num_rows === 0) { ?>
    <div class="empty-state">
      <p class="section-label">Empty</p>
      <h2>Nothing marked to get rid of</h2>
      <p>When an item no longer earns its keep, mark it from the interact-by queue. It will land here.</p>
      <a class="secondary-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">Review interact-by queue</a>
    </div>
  <?php } else { ?>

    <div class="surface-panel">
    <div class="table-scroll">
    <table class="list">
      <thead>
        <tr id="headerRow">
          <th>Name (<?php echo $artifact_set->num_rows; ?>)</th>
          <th>Type</th>
          <th>Last Interaction</th>
          <th>Tracking Start</th>
          <?php if (!is_guest()) { ?><th>Restore</th>
          <th>Kept</th><?php } ?>
        </tr>
      </thead>

      <tbody>
        <?php while ($artifact = mysqli_fetch_assoc($artifact_set)) {
          $id = h(u($artifact['id']));
          $is_kept = artifact_is_kept($artifact);
        ?>
          <tr>
            <td class="name">
              <a href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . $id); ?>">
                <?php echo h($artifact['Title']); ?>
              </a>
            </td>

            <td class="type"><?php echo h($artifact['type']); ?></td>

            <td class="date">
              <?php echo h(substr($artifact['MostRecentUseOrResponse'], 0, 10)); ?>
            </td>

            <td class="date"><?php echo h($artifact['Acq']); ?></td>

            <?php if (!is_guest()) { ?>
            <td>
              <form method="post" action="<?php echo url_for('/artifacts/mark-get-rid-of.php'); ?>" style="display:inline; margin:0;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
                <input type="hidden" name="artifact_name" value="<?php echo h($artifact['Title']); ?>">
                <input type="hidden" name="value" value="0">
                <input type="hidden" name="return_to" value="to-get-rid-of">
                <button type="submit" class="restore-btn">Restore</button>
              </form>
            </td>

            <td class="kept" data-artifact-id="<?php echo $id; ?>" data-kept="<?php echo $is_kept ? '1' : '0'; ?>">
              <form method="post" action="<?php echo url_for('/artifacts/set-tracked.php'); ?>" class="kept-toggle-form" style="display:inline; margin:0;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
                <input type="hidden" name="artifact_name" value="<?php echo h($artifact['Title']); ?>">
                <input type="hidden" name="value" value="<?php echo $is_kept ? '0' : '1'; ?>">
                <input type="hidden" name="return_to" value="to-get-rid-of">
                <button type="submit" class="kept-toggle-btn" aria-pressed="<?php echo $is_kept ? 'true' : 'false'; ?>">
                  <?php echo $is_kept ? 'Kept' : 'Keep'; ?>
                </button>
              </form>
            </td>
            <?php } ?>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    </div>
    </div>

  <?php } ?>

  <?php mysqli_free_result($artifact_set); ?>

  <div id="rid-of-toast" class="toast" role="status" aria-live="polite"></div>
  <script>
    (function () {
      var toastEl = document.getElementById('rid-of-toast');
      var toastTimer = null;
      function showToast(message, kind) {
        if (!toastEl) { window.alert(message); return; }
        toastEl.textContent = message;
        toastEl.classList.remove('toast-success', 'toast-error', 'is-visible');
        toastEl.classList.add(kind === 'error' ? 'toast-error' : 'toast-success');
        void toastEl.offsetWidth;
        toastEl.classList.add('is-visible');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
          toastEl.classList.remove('is-visible');
        }, 3500);
      }

      document.querySelectorAll('form.kept-toggle-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var cell = form.closest('td.kept');
          var button = form.querySelector('.kept-toggle-btn');
          var valueInput = form.querySelector('input[name="value"]');
          var nameInput = form.querySelector('input[name="artifact_name"]');
          var name = (nameInput && nameInput.value) ? nameInput.value : 'this item';
          if (valueInput && valueInput.value === '0') {
            if (!confirm('Remove ' + name + ' from kept?')) {
              return;
            }
          }
          if (button) { button.disabled = true; }
          fetch(form.action, {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: new FormData(form),
          })
            .then(function (response) {
              return response.json().then(function (data) {
                return { ok: response.ok, data: data };
              });
            })
            .then(function (result) {
              if (result.ok && result.data && result.data.ok) {
                var isKept = result.data.is_kept === 1;
                if (cell) { cell.dataset.kept = isKept ? '1' : '0'; }
                if (button) {
                  button.textContent = isKept ? 'Kept' : 'Keep';
                  button.setAttribute('aria-pressed', isKept ? 'true' : 'false');
                }
                if (valueInput) { valueInput.value = isKept ? '0' : '1'; }
                showToast(result.data.message || 'Updated.', 'success');
              } else {
                showToast((result.data && result.data.message) || 'Request failed', 'error');
              }
              if (button) { button.disabled = false; }
            })
            .catch(function (error) {
              showToast('Network error: ' + error.message, 'error');
              if (button) { button.disabled = false; }
            });
        });
      });
    })();
  </script>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

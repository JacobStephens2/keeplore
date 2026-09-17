<?php
require_once('../private/initialize.php');

if (!is_logged_in() && !is_guest()) {
  require SHARED_PATH . '/landing.php';
  exit;
}

require_login_or_guest();
$page_title = 'Menu';

// Fetch user's default interval and snooze length
$user_id = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($db, "SELECT default_use_interval, default_snooze_days FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$interval_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$default_interval = (float) ($interval_row['default_use_interval'] ?? 90);
$default_snooze_days = (int) ($interval_row['default_snooze_days'] ?? 7);
if ($default_snooze_days < 1) {
  $default_snooze_days = 7;
}

// Fetch tracked artifacts with most recent use dates (same query as use_by())
$stmt = mysqli_prepare($db, "SELECT
    games.id,
    games.Title,
    games.Acq,
    games.interaction_frequency_days,
    types.objectType AS type,
    CASE
      WHEN MAX(uses.use_date) IS NULL THEN MAX(responses.PlayDate)
      WHEN MAX(uses.use_date) < MAX(responses.PlayDate) THEN MAX(responses.PlayDate)
      ELSE MAX(uses.use_date)
    END AS MostRecentUseOrResponse
  FROM games
    LEFT JOIN responses ON games.id = responses.Title
    LEFT JOIN uses ON games.id = uses.artifact_id
    LEFT JOIN types ON games.type_id = types.id
  GROUP BY games.id, games.Title, games.Acq, games.interaction_frequency_days, types.objectType, games.is_kept, games.user_id, games.to_get_rid_of, games.snoozed_until
  HAVING games.user_id = ? AND games.is_kept = 1 AND (games.to_get_rid_of = 0 OR games.to_get_rid_of IS NULL)
    AND (games.snoozed_until IS NULL OR games.snoozed_until <= CURDATE())
  ORDER BY MostRecentUseOrResponse ASC");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$artifact_result = mysqli_stmt_get_result($stmt);

// Calculate use-by dates and find top 5 most overdue
date_default_timezone_set('America/New_York');
$now = new DateTime(date('Y-m-d'));
$overdue_items = [];

while ($artifact = mysqli_fetch_assoc($artifact_result)) {
  $this_interval = $artifact['interaction_frequency_days'] !== null
    ? (float) $artifact['interaction_frequency_days']
    : $default_interval;

  $acq = new DateTime(substr($artifact['Acq'], 0, 10));

  if ($artifact['MostRecentUseOrResponse'] === null) {
    $base = clone $acq;
    $hours = (int)($this_interval * 24);
  } else {
    $recent = new DateTime(substr($artifact['MostRecentUseOrResponse'], 0, 10));
    if ($recent < $acq) {
      $base = clone $acq;
      $hours = (int)($this_interval * 24);
    } else {
      $base = clone $recent;
      $hours = (int)($this_interval * 2 * 24);
    }
  }

  $use_by = $base->add(DateInterval::createFromDateString("$hours hours"));
  $diff = (int) $now->diff($use_by)->format('%r%a'); // negative = overdue

  $overdue_items[] = [
    'id' => $artifact['id'],
    'title' => $artifact['Title'],
    'type' => $artifact['type'],
    'use_by' => $use_by->format('Y-m-d'),
    'days_diff' => $diff,
    'most_recent' => $artifact['MostRecentUseOrResponse'] !== null
      ? substr($artifact['MostRecentUseOrResponse'], 0, 10)
      : null,
  ];
}
mysqli_stmt_close($stmt);

// Sort by use-by date ascending (most overdue first)
usort($overdue_items, fn($a, $b) => $a['days_diff'] <=> $b['days_diff']);
// Render up to 8 cards; CSS hides cards 6-8 on viewports that don't have
// room for a 4-column grid so they only show when there's space.
$top_overdue = array_slice($overdue_items, 0, 8);
$tracked_count = count($overdue_items);
$overdue_count = 0;
$due_soon_count = 0;
$type_names = [];

foreach ($overdue_items as $item) {
  if ($item['days_diff'] < 0) {
    $overdue_count++;
  }

  if ($item['days_diff'] >= 0 && $item['days_diff'] <= 14) {
    $due_soon_count++;
  }

  if (!empty($item['type'])) {
    $type_names[$item['type']] = true;
  }
}

$type_count = count($type_names);

include(SHARED_PATH . '/header.php');
?>

<main>
  <div id="main-menu" class="dashboard">
    <section class="dashboard-hero">
      <div class="dashboard-hero-copy">
        <p class="section-label">Dashboard</p>
        <h1>Main Menu</h1>
        <p class="dashboard-intro">
          See what needs attention, check the queue, and record interactions without leaving this page.
        </p>

        <?php if (!is_guest()) { ?>
        <a class="dashboard-record-btn" href="<?php echo url_for('/uses/record-new.php'); ?>">
          <span class="dashboard-record-icon" aria-hidden="true">+</span>
          Record interaction
        </a>
        <?php } ?>

        <div class="dashboard-actions">
          <a class="prominent-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">
            Review interaction queue
          </a>
          <a class="secondary-link" href="<?php echo url_for('/artifacts/index.php'); ?>">
            Browse all items
          </a>
        </div>
      </div>

      <div class="dashboard-hero-aside">
        <div class="metric-card">
          <span class="metric-label">Tracked</span>
          <strong><?php echo h((string) $tracked_count); ?></strong>
        </div>
        <a class="metric-card metric-card-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">
          <span class="metric-label">Overdue</span>
          <strong><?php echo h((string) $overdue_count); ?></strong>
        </a>
        <div class="metric-card">
          <span class="metric-label">Due In 14 Days</span>
          <strong><?php echo h((string) $due_soon_count); ?></strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Types In Rotation</span>
          <strong><?php echo h((string) $type_count); ?></strong>
        </div>
      </div>
    </section>

    <div class="dashboard-grid">
      <?php if (!empty($top_overdue)) { ?>
      <section id="priority-queue" class="menu-card overdue-card">
        <p class="section-label">Priority Queue</p>
        <h2 class="menu-card-title">Most past due</h2>
        <ul class="overdue-list">
          <?php foreach ($top_overdue as $item) {
            $overdue = $item['days_diff'] < 0;
          ?>
            <li class="overdue-item-card">
              <div class="overdue-item-head">
                <a class="overdue-item-title" href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($item['id']))); ?>">
                  <?php echo h($item['title']); ?>
                </a>
                <?php if (!empty($item['type'])) { ?>
                  <span class="status-chip"><?php echo h($item['type']); ?></span>
                <?php } ?>
              </div>
              <p class="overdue-item-date<?php if ($overdue) echo ' overdue-past'; ?>">
                <span class="overdue-item-date-label">Interact by</span>
                <?php echo h($item['use_by']); ?>
              </p>
              <p class="overdue-item-date overdue-item-date-last">
                <span class="overdue-item-date-label">Last interacted</span>
                <?php echo $item['most_recent'] !== null ? h($item['most_recent']) : '—'; ?>
              </p>
              <?php if (!is_guest()) { ?>
              <div class="overdue-item-actions">
                <a class="menu-link" href="/uses/record-new?artifact_id=<?php echo h(u($item['id'])); ?>">Record</a>
                <form method="post" action="<?php echo url_for('/artifacts/snooze.php'); ?>" class="overdue-item-snooze">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="artifact_id" value="<?php echo h($item['id']); ?>">
                  <input type="hidden" name="artifact_name" value="<?php echo h($item['title']); ?>">
                  <input type="hidden" name="return_to" value="dashboard">
                  <button type="submit" class="snooze-btn" title="Hide for <?php echo h((string) $default_snooze_days); ?> day<?php echo $default_snooze_days === 1 ? '' : 's'; ?>">Snooze</button>
                </form>
                <form method="post" action="<?php echo url_for('/artifacts/mark-get-rid-of.php'); ?>" class="overdue-item-getridof">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="artifact_id" value="<?php echo h($item['id']); ?>">
                  <input type="hidden" name="artifact_name" value="<?php echo h($item['title']); ?>">
                  <input type="hidden" name="return_to" value="dashboard">
                  <button type="submit" class="get-rid-of-btn">Get Rid Of</button>
                </form>
              </div>
              <?php } ?>
            </li>
          <?php } ?>
        </ul>
        <a class="menu-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">View full queue</a>
      </section>
      <?php } ?>
    </div>

    <div class="menu-grid">
      <div class="menu-card">
        <p class="section-label">Queue</p>
        <h2 class="menu-card-title">Interact By Date</h2>
        <p class="menu-support">See what is due and take action on the items that need attention first.</p>
        <a class="menu-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">Interact by date list</a>
        <?php if (!is_guest()) { ?><a class="menu-link" href="/uses/record-new.php">Record interaction</a><?php } ?>
      </div>

      <div class="menu-card">
        <p class="section-label">Library</p>
        <h2 class="menu-card-title">Items</h2>
        <p class="menu-support">Audit what is active, what is archived, and what needs to leave the shelf.</p>
        <a class="menu-link" href="<?php echo url_for('/artifacts/index.php'); ?>">All items</a>
        <?php if (!is_guest()) { ?><a class="menu-link" href="<?php echo url_for('/artifacts/new.php'); ?>">Create new item</a><?php } ?>
        <a class="menu-link" href="<?php echo url_for('/artifacts/to-get-rid-of.php'); ?>">To get rid of</a>
      </div>

      <div class="menu-card">
        <p class="section-label">Activity</p>
        <h2 class="menu-card-title">Interactions</h2>
        <p class="menu-support">Record new activity and review the full history across the collection.</p>
        <a class="menu-link" href="<?php echo url_for('/uses/interactions.php'); ?>">All interactions</a>
        <?php if (!is_guest()) { ?><a class="menu-link" href="/uses/record-new.php">Record interaction</a><?php } ?>
      </div>

      <?php if (!is_guest()) { ?>
      <div class="menu-card">
        <p class="section-label">People</p>
        <h2 class="menu-card-title">Users</h2>
        <p class="menu-support">Manage the people connected to your collection.</p>
        <a class="menu-link" href="<?php echo url_for('/users/index.php'); ?>">Users</a>
        <a class="menu-link" href="<?php echo url_for('/users/new.php'); ?>">Add new user</a>
        <a class="menu-link" href="<?php echo url_for('/explore/candidates.php'); ?>">Candidates</a>
      </div>

      <div class="menu-card">
        <p class="section-label">Account</p>
        <h2 class="menu-card-title">Settings</h2>
        <p class="menu-support">Adjust your defaults, email preferences, and account details.</p>
        <a class="menu-link" href="<?php echo url_for('/settings/edit.php'); ?>">Settings</a>
        <a class="menu-link" href="<?php echo url_for('/reset-password/index.php'); ?>">Reset password</a>
        <a class="menu-link" href="<?php echo url_for('/archive.php'); ?>">Archived pages</a>
      </div>
      <?php } ?>
    </div>

    <p class="menu-about">
      Keeplore generates interact-by dates so the things you keep stay in use. Inspired by
      <a href="https://www.theminimalists.com/ninety/" target="_blank">The Minimalists' 90/90 Rule</a>
      and built by
      <a href="https://jacobstephens.net" target="_blank">Jacob Stephens</a>.
    </p>
  </div>

  <?php if (!is_guest()) { ?>
  <?php
    $dashboard_default_setting = singleValueQuery(
      "SELECT note FROM uses WHERE user_id = '" . (int) $_SESSION['user_id'] . "' ORDER BY id DESC LIMIT 1"
    );
  ?>
  <div id="dashboard-toast" class="toast" role="status" aria-live="polite"></div>
  <div id="record-modal" class="modal" hidden aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="record-modal-title">
      <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
      <h2 id="record-modal-title" class="modal-title">Record interaction</h2>
      <p id="record-modal-artifact" class="modal-subtitle"></p>
      <form id="record-modal-form" method="post" action="<?php echo url_for('/uses/record-new.php'); ?>">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="artifact[id]" id="record-modal-artifact-id">
        <input type="hidden" name="artifact[name]" id="record-modal-artifact-name">
        <input type="hidden" name="user[0][id]" value="<?php echo h($_SESSION['player_id'] ?? ''); ?>">
        <input type="hidden" name="user[0][name]" value="<?php echo h($_SESSION['FullName'] ?? ''); ?>">

        <label for="record-modal-date">Date</label>
        <input type="date" name="useDate" id="record-modal-date" required>

        <label for="record-modal-setting">Setting</label>
        <input type="text" name="Note" id="record-modal-setting" value="<?php echo h($dashboard_default_setting ?? ''); ?>">

        <label for="record-modal-notes">Notes</label>
        <textarea name="NotesTwo" id="record-modal-notes" rows="3"></textarea>

        <div class="modal-actions">
          <a class="modal-link" id="record-modal-fullform-link" href="#" target="_blank">Open full form</a>
          <button type="button" class="modal-cancel" data-modal-close>Cancel</button>
          <button type="submit" class="modal-save">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var modal = document.getElementById('record-modal');
      if (!modal) return;
      var form = document.getElementById('record-modal-form');
      var artifactIdInput = document.getElementById('record-modal-artifact-id');
      var artifactNameInput = document.getElementById('record-modal-artifact-name');
      var displayEl = document.getElementById('record-modal-artifact');
      var dateInput = document.getElementById('record-modal-date');
      var notesInput = document.getElementById('record-modal-notes');
      var saveBtn = form.querySelector('.modal-save');
      var fullFormLink = document.getElementById('record-modal-fullform-link');
      var toastEl = document.getElementById('dashboard-toast');
      var toastTimer = null;
      var currentRow = null;

      function showToast(message, kind) {
        if (!toastEl) { alert(message); return; }
        toastEl.textContent = message;
        toastEl.classList.remove('toast-success', 'toast-error', 'is-visible');
        toastEl.classList.add(kind === 'error' ? 'toast-error' : 'toast-success');
        void toastEl.offsetWidth;
        toastEl.classList.add('is-visible');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 3500);
      }

      function todayLocal() {
        var d = new Date();
        return d.getFullYear() + '-'
          + String(d.getMonth() + 1).padStart(2, '0') + '-'
          + String(d.getDate()).padStart(2, '0');
      }

      function openModal(artifactId, artifactName, row) {
        currentRow = row;
        artifactIdInput.value = artifactId;
        artifactNameInput.value = artifactName;
        displayEl.textContent = artifactName;
        dateInput.value = todayLocal();
        notesInput.value = '';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (fullFormLink) {
          fullFormLink.href = '/uses/record-new.php?artifact_id=' + encodeURIComponent(artifactId);
        }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        window.setTimeout(function () { dateInput.focus(); }, 30);
      }

      function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        currentRow = null;
      }

      modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
      });

      document.querySelectorAll('.overdue-list a[href*="record-new"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          var li = link.closest('li');
          var idMatch = (link.getAttribute('href') || '').match(/artifact_id=(\d+)/);
          var artifactId = idMatch ? idMatch[1] : null;
          var titleEl = li ? li.querySelector('.overdue-item-title') : null;
          var artifactName = titleEl ? titleEl.textContent.trim() : '';
          if (!artifactId) return;
          openModal(artifactId, artifactName, li);
        });
      });

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        fetch(form.action, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          body: new FormData(form),
        })
          .then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
          })
          .then(function (result) {
            if (result.ok && result.data && result.data.ok) {
              handleSuccess(result.data, currentRow);
              closeModal();
              showToast(result.data.message || 'Interaction recorded.', 'success');
            } else {
              var msg = (result.data && result.data.message) || 'Request failed';
              showToast(msg, 'error');
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save';
            }
          })
          .catch(function (error) {
            showToast('Network error: ' + error.message, 'error');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
          });
      });

      function handleSuccess(data, row) {
        if (!row) return;
        if (!data.is_overdue) {
          row.remove();
          var section = document.getElementById('priority-queue');
          var ul = section ? section.querySelector('.overdue-list') : null;
          if (ul && !ul.querySelector('li')) {
            section.style.display = 'none';
          }
        }
      }
    })();
  </script>
  <?php } ?>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php  // require login
  require_once('../../private/initialize.php');
  require_login_or_guest();
?>

<?php // load header
  $page_title = 'Interact By';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html'); 
?>
<script defer src="/shared/filter_button.js"></script>
<script defer src="useby.js?v=7"></script>

<?php // process form submission and initialize variables
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['type'])) {
      $type = $_POST['type'];
    } else {
      $type = [];
    }
  } else {
    if (isset($_SESSION['type']) && count($_SESSION['type']) > 0) {
      $type = $_SESSION['type'];
    } else {
      include(SHARED_PATH . '/artifact_type_array.php'); 
      global $typesArray;
      $type = $typesArray;
    }
  }

  $user_id = $_SESSION['user_id'];
  $_SESSION['type'] = $type;
  $sweetSpot = $_POST['sweetSpot'] ?? '';
  $minimumAge = $_POST['minimumAge'] ?? 0;
  $shelfSort = $_POST['shelfSort'] ?? 'no';
  $showAttributes = $_POST['showAttributes'] ?? 'no';
  $showInterval = $_POST['showInterval'] ?? 'no';
  // Hide snoozed items by default, and remember the user's last choice
  // across future page loads (mirrors how $type is persisted in the session).
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hideSnoozed = $_POST['hideSnoozed'] ?? 'no';
  } else {
    $hideSnoozed = $_SESSION['hideSnoozed'] ?? 'yes';
  }
  $_SESSION['hideSnoozed'] = $hideSnoozed;
  $typeArray = $_SESSION['type'] ?? [];
  $default_use_interval = singleValueQuery("SELECT default_use_interval
    FROM users
    WHERE id = '$user_id'
  ");
  $interval = $_POST['interval'] ?? $default_use_interval;
  $artifact_set = use_by($type, $interval, $sweetSpot, $minimumAge, $shelfSort, null, $hideSnoozed === 'yes');
  $total_overdue = 0;
?>

<main class="useby-page">

  <meta id="apiOrigin" content="<?php echo API_ORIGIN; ?>">

  <header class="page-header page-header-row">
    <div>
      <p class="section-label">Queue</p>
      <h1>
        <a class="hideOnPrint" target="_blank"
          href="<?php echo url_for('/artifacts/about-useby.php'); ?>"
          >
          Interact by date
        </a>
      </h1>
      <p class="page-lede hideOnPrint">What is due next, ordered so you use what you keep.</p>
    </div>
    <div class="page-header-actions">
      <?php if (!is_guest()) { ?><button id="send_use_email" data-userid="<?php echo $user_id; ?>">Send interact email</button><?php } ?>
      <div id="view_toggle" class="view-toggle" role="group" aria-label="View mode">
        <button type="button" class="view-toggle-btn" data-view="table" aria-pressed="false">Table</button>
        <button type="button" class="view-toggle-btn" data-view="cards" aria-pressed="false">Cards</button>
      </div>
      <button type="button" id="display_filters">Show filters</button>
    </div>
  </header>

  <form class="filter-panel" action="<?php echo url_for('/artifacts/useby.php'); ?>"
    id="useby-filters"
    method="post"
    style="display: none"
    >
    <?php echo csrf_input(); ?>
    <div class="hideOnPrint">

      <label for="artifactType">Item type</label>
      <section id="artifactType" class="type-chip-group">
        <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
      </section>

      <label for="sweetSpot">Sweet Spot</label>
      <input type="number" name="sweetSpot" id="sweetSpot" value="<?php echo $sweetSpot; ?>">

      <label for="minimumAge">Minimum Age</label>
      <input type="number" name="minimumAge" id="minimumAge" value="<?php echo $minimumAge; ?>">
      
      <label for="shelfSort">Shelf Sort (Instead of Interact By Sort)</label>
      <input type="hidden" name="shelfSort" value="no">
      <input type="checkbox" name="shelfSort" id="shelfSort" value="yes"
        <?php 
          if ($shelfSort === 'yes') {
            echo ' checked ';
          }
        ?>
      >
      
      <label for="showAttributes">Show item attributes</label>
      <input type="hidden" name="showAttributes" value="no">
      <input type="checkbox" name="showAttributes" id="showAttributes" value="yes"
        <?php
          if ($showAttributes === 'yes') {
            echo ' checked ';
          }
        ?>
      >

      <label for="showInterval">Show interval column</label>
      <input type="hidden" name="showInterval" value="no">
      <input type="checkbox" name="showInterval" id="showInterval" value="yes"
        <?php
          if ($showInterval === 'yes') {
            echo ' checked ';
          }
        ?>
      >

      <label for="hideSnoozed">Hide snoozed items</label>
      <input type="hidden" name="hideSnoozed" value="no">
      <input type="checkbox" name="hideSnoozed" id="hideSnoozed" value="yes"
        <?php
          if ($hideSnoozed === 'yes') {
            echo ' checked ';
          }
        ?>
      >

    </div>

    <div class="displayOnPrint">
      <label for="interval">Interval in days from most recent or to upcoming use</label>
      <input type="number" step="0.1" name="interval" id="interval" value="<?php echo $interval ?>">
    </div>
    
    <input type="submit" value="Submit" class="hideOnPrint"/>
  
    <section id="legend">
      <p>U stands for used at recommended user count or used fully through at non-recommended count</p>
    </section>
  </form>

  <p class="copied_message" style="display: none"></p>
  <div id="useby-toast" class="toast" role="status" aria-live="polite"></div>

  <?php if (!is_guest()) { ?>
  <?php
    $modal_default_setting = singleValueQuery(
      "SELECT note FROM uses WHERE user_id = '" . (int) $_SESSION['user_id'] . "' ORDER BY id DESC LIMIT 1"
    );
  ?>
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

        <label>People</label>
        <div id="record-modal-users">
          <div class="modal-user-chip" data-user-index="0">
            <span class="modal-user-name"><?php echo h($_SESSION['FullName'] ?? 'Me'); ?></span>
            <input type="hidden" name="user[0][id]" value="<?php echo h($_SESSION['player_id'] ?? ''); ?>" data-player-id="<?php echo h($_SESSION['player_id'] ?? ''); ?>">
            <input type="hidden" name="user[0][name]" value="<?php echo h($_SESSION['FullName'] ?? ''); ?>">
          </div>
        </div>

        <div id="record-modal-add-user">
          <div class="modal-user-search-wrap">
            <input type="search" id="record-modal-user-search" placeholder="Add another person…" autocomplete="off">
            <ul id="record-modal-user-results" class="modal-user-results" hidden></ul>
          </div>
          <button type="button" id="record-modal-new-user-toggle" class="new-interactor-toggle">+ New person</button>
        </div>

        <div id="record-modal-new-user-form" class="new-interactor-form" style="display: none;">
          <input type="text" id="record-modal-new-first" placeholder="First name" autocomplete="off">
          <input type="text" id="record-modal-new-last" placeholder="Last name" autocomplete="off">
          <button type="button" id="record-modal-new-create" class="new-interactor-create">Create &amp; add</button>
          <button type="button" id="record-modal-new-cancel" class="new-interactor-cancel">Cancel</button>
          <span id="record-modal-new-msg" class="new-interactor-msg" role="status" aria-live="polite"></span>
        </div>

        <label for="record-modal-date">Date</label>
        <input type="date" name="useDate" id="record-modal-date" required>

        <label for="record-modal-setting">Setting</label>
        <input type="text" name="Note" id="record-modal-setting" value="<?php echo h($modal_default_setting ?? ''); ?>">

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
  <?php } ?>

  <div class="table-scroll">
  <table id="useBy" class="list" data-page-length='100'>
    <thead>
      <tr id="headerRow">
        <th>Name (<?php echo $artifact_set->num_rows; ?>)</th>
        <th>Interact By</th>
        <?php if (!is_guest()) { ?><th>Record</th><?php } ?>
        <th>Type</th>
        <?php
          if ($showAttributes === 'yes') {
            ?>
            <th>SwS</th>
            <th>AvgT</th>
            <th>Age</th>
            <th>SwS's</th>
            <th>MnP</th>
            <th>MxP</th>
            <th>C</th>
            <?php
          } else {
            ?>
            <?php
          }
        ?>
        <?php if (!is_guest()) { ?><th class="hideOnPrint">Get Rid Of</th><?php } ?>
        <th>Overdue (<span id="totalOverdue"></span>)</th>
        <th class="hideOnPrint">Recent Interaction</th>
        <th>Tracking Start</th>
        <?php if ($showInterval === 'yes') { ?><th>Interval</th><?php } ?>
      </tr>
    </thead>

    <tbody>
      <?php while($artifact = mysqli_fetch_assoc($artifact_set)) {
        $id = h(u($artifact['id']));
        if ($artifact['interaction_frequency_days'] !== null) {
          $this_interval = $artifact['interaction_frequency_days'];
        } else {
          $this_interval = $interval;
        }
        $snoozed_until = $artifact['snoozed_until'] ?? null;
        $is_snoozed = $snoozed_until !== null && $snoozed_until > date('Y-m-d');
        ?>
        <tr>
          <td class="name artifact edit" data-label="Name">
            <div>
              <a id="artifact_id_<?php echo $id; ?>"
                class="action edit"
                href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . $id); ?>"
                ><?php echo h($artifact['Title']);
              ?></a>
              <img class="clipboard"
                id="artifact_id_copy_<?php echo $id; ?>"
                src="/assets/copy.png"
                alt="A clipboard icon for copying"
              >
              <?php if ($is_snoozed) { ?>
                <span class="snoozed-badge" title="Hidden from the dashboard priority queue until this date">Snoozed until <?php echo h($snoozed_until); ?></span>
              <?php } ?>

              <script>
                document
                  .querySelector('img#artifact_id_copy_<?php echo $id; ?>')
                  .addEventListener('click', function() {
                    let text = document.querySelector('a#artifact_id_<?php echo $id; ?>').innerHTML;
                    navigator.clipboard.writeText(text);
                    var copied_message = document.querySelector('p.copied_message');
                    copied_message.innerText = text + ' copied';
                    copied_message.style.display = 'block';
                    setTimeout(() => {
                      copied_message.innerText = '';
                      copied_message.style.display = 'none';
                    }, 1500);
                  }
                );

              </script>
            </div>
          </td>

          <?php
              date_default_timezone_set('America/New_York');
              $DateTimeNow = new DateTime(date('Y-m-d'));
              $DateTimeMostRecentUse = new DateTime(substr($artifact['MostRecentUseOrResponse'],0,10));
              $DateTimeAcquisition = new DateTime(substr($artifact['Acq'],0,10));

              $intervalInHours = $this_interval * 24;

              if ($DateTimeMostRecentUse < $DateTimeAcquisition || $artifact['MostRecentUseOrResponse'] === NULL) {
                $DateInterval = DateInterval::createFromDateString("$intervalInHours hour");
                $useByDate = date_add($DateTimeAcquisition, $DateInterval);
              } else {
                $doubledInterval = $intervalInHours * 2;
                $DateInterval = DateInterval::createFromDateString("$doubledInterval hour");
                $useByDate = date_add($DateTimeMostRecentUse, $DateInterval);
              }
          ?>

          <td class="useByDate date<?php if ($useByDate < $DateTimeNow) echo ' overdue-past'; ?>" data-label="Interact by"><?php print_r($useByDate->format('Y-m-d')); ?></td>

            <?php if (!is_guest()) { ?>
            <td class="record" data-label="Record">
              <a href="/uses/record-new?artifact_id=<?php echo $id; ?>"
                target="_blank"
                >
                Record
              </a>
            </td>
            <?php } ?>

          <td class="type" data-label="Type"><?php echo h($artifact['type']); ?></td>

          <?php
          if ($showAttributes === 'yes') {
            ?>
            <td class="SwS" data-label="SwS">
              <?php
                // find the first number without leading zeros
                preg_match(
                  '/([1-9][0-9])|[1-9]/',
                  $artifact['ss'],
                  $match
                );
                echo h($match[0]);
              ?>
            </td>

            <td class="AvgT" data-label="AvgT"><?php echo (h($artifact['mnt']) + h($artifact['mxt'])) / 2; ?></td>
            <td class="Age" data-label="Age"><?php echo h($artifact['age']); ?></td>
            <td class="SwSs" data-label="SwS's"><?php echo h($artifact['ss']); ?></td>
            <td class="MnP" data-label="MnP"><?php echo h($artifact['mnp']); ?></td>
            <td class="MxP" data-label="MxP"><?php echo h($artifact['mxp']); ?></td>

            <td class="candidate" data-label="Candidate">
              <?php
              if ( strlen($artifact['Candidate']) > 0 ) {
                echo 'Yes';
              }
              ?>
            </td>
            <?php
          }
          ?>

          <?php if (!is_guest()) { ?>
          <td class="get-rid-of hideOnPrint" data-label="Actions">
            <form method="post" action="<?php echo url_for('/artifacts/mark-get-rid-of.php'); ?>" class="get-rid-of-form" style="margin:0;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
              <input type="hidden" name="artifact_name" value="<?php echo h($artifact['Title']); ?>">
              <input type="hidden" name="return_to" value="useby">
              <button type="submit" class="get-rid-of-btn">Get Rid Of</button>
            </form>
            <form method="post" action="<?php echo url_for('/artifacts/set-tracked.php'); ?>" class="untrack-form" style="margin:0;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="artifact_id" value="<?php echo $id; ?>">
              <input type="hidden" name="artifact_name" value="<?php echo h($artifact['Title']); ?>">
              <input type="hidden" name="value" value="0">
              <input type="hidden" name="return_to" value="useby">
              <button type="submit" class="untrack-btn">Remove</button>
            </form>
          </td>
          <?php } ?>

          <td class="overdue" data-label="Overdue"
            <?php
                if ($useByDate < $DateTimeNow) {
                  echo 'style="color: red;"';
                }
            ?>
            >
            <?php
                if ($useByDate < $DateTimeNow) {
                  $total_overdue++;
                  echo 'Yes';
                } else {
                  echo 'No';
                }
              ?>
          </td>

          <td class="mostRecentUse date hideOnPrint" data-label="Last interacted">
            <?php
              $mostRecent = substr($artifact['MostRecentUseOrResponse'] ?? '', 0, 10);
              echo $mostRecent !== '' ? h($mostRecent) : '—';
            ?>
          </td>

          <td class="acquisitionDate" data-label="Tracking start"><?php echo h($artifact['Acq']); ?></td>
          <?php if ($showInterval === 'yes') { ?>
          <td class="interval" data-label="Interval"><?php echo $this_interval; ?></td>
          <?php } ?>
        </tr>
      <?php } ?>
    </tbody>
  </table>
  </div>

  <?php mysqli_free_result($artifact_set); ?>
  <script>
    document.querySelector('span#totalOverdue').innerText = '<?php echo $total_overdue; ?>';
    <?php
      // Compute column indices based on which columns are actually rendered
      // so the DataTable order config does not reference missing columns
      // (e.g. Record / Get Rid Of are hidden in guest mode, Interval is
      // hidden unless its filter is on).
      $colIdx = [];
      $col = 0;
      $colIdx['name'] = $col++;
      $colIdx['interactBy'] = $col++;
      if (!is_guest()) { $colIdx['record'] = $col++; }
      $colIdx['type'] = $col++;
      if ($showAttributes === 'yes') {
        $colIdx['sws'] = $col++;
        $colIdx['avgt'] = $col++;
        $colIdx['age'] = $col++;
        $colIdx['swss'] = $col++;
        $colIdx['mnp'] = $col++;
        $colIdx['mxp'] = $col++;
        $colIdx['candidate'] = $col++;
      }
      if (!is_guest()) { $colIdx['getRidOf'] = $col++; }
      $colIdx['overdue'] = $col++;
      $colIdx['recentUse'] = $col++;
      $colIdx['acq'] = $col++;
      if ($showInterval === 'yes') { $colIdx['interval'] = $col++; }
    ?>
    let table = new DataTable('#useBy', {
      // options
      <?php
        if ($shelfSort === 'yes' && $showAttributes === 'yes') {
          ?>
          order: [
            [ <?php echo $colIdx['type']; ?>, 'asc'],
            [ <?php echo $colIdx['sws']; ?>, 'asc'],
            [ <?php echo $colIdx['avgt']; ?>, 'asc'],
            [ <?php echo $colIdx['age']; ?>, 'asc'],
            [ <?php echo $colIdx['swss']; ?>, 'asc'],
            [ <?php echo $colIdx['mnp']; ?>, 'asc'],
            [ <?php echo $colIdx['mxp']; ?>, 'asc'],
            [ <?php echo $colIdx['recentUse']; ?>, 'desc'],
            [ <?php echo $colIdx['candidate']; ?>, 'desc'],
          ]
          <?php
        } elseif ($showAttributes === 'yes') {
          ?>
          order: [
            [ <?php echo $colIdx['interactBy']; ?>, 'asc'],
            [ <?php echo $colIdx['avgt']; ?>, 'asc'],
            [ <?php echo $colIdx['age']; ?>, 'asc'],
          ]
          <?php
        } else {
          ?>
          order: [
            [ <?php echo $colIdx['interactBy']; ?>, 'asc'],
            [ <?php echo $colIdx['recentUse']; ?>, 'asc'],
            [ <?php echo $colIdx['acq']; ?>, 'asc'],
          ]
          <?php
        }
      ?>
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
      var toastEl = document.getElementById('useby-toast');
      var toastTimer = null;
      function showToast(message, kind) {
        if (!toastEl) { alert(message); return; }
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

      var overdueSpan = document.querySelector('span#totalOverdue');

      var recordModal = document.getElementById('record-modal');
      var recordForm = document.getElementById('record-modal-form');
      var modalArtifactInput = document.getElementById('record-modal-artifact-id');
      var modalArtifactNameInput = document.getElementById('record-modal-artifact-name');
      var modalArtifactDisplay = document.getElementById('record-modal-artifact');
      var modalDateInput = document.getElementById('record-modal-date');
      var modalSettingInput = document.getElementById('record-modal-setting');
      var modalNotesInput = document.getElementById('record-modal-notes');
      var modalSaveBtn = recordForm ? recordForm.querySelector('.modal-save') : null;
      var modalFullFormLink = document.getElementById('record-modal-fullform-link');
      var currentRecordRow = null;

      function todayLocal() {
        var d = new Date();
        return d.getFullYear() + '-'
          + String(d.getMonth() + 1).padStart(2, '0') + '-'
          + String(d.getDate()).padStart(2, '0');
      }

      function openRecordModal(artifactId, artifactName, tr) {
        if (!recordModal) return;
        currentRecordRow = tr;
        modalArtifactInput.value = artifactId;
        modalArtifactNameInput.value = artifactName;
        modalArtifactDisplay.textContent = artifactName;
        modalDateInput.value = todayLocal();
        modalNotesInput.value = '';
        if (window.recordModalResetUsers) { window.recordModalResetUsers(); }
        if (modalSaveBtn) { modalSaveBtn.disabled = false; modalSaveBtn.textContent = 'Save'; }
        if (modalFullFormLink) {
          modalFullFormLink.href = '/uses/record-new.php?artifact_id=' + encodeURIComponent(artifactId);
        }
        recordModal.hidden = false;
        recordModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        window.setTimeout(function () { modalDateInput.focus(); }, 30);
      }

      function closeRecordModal() {
        if (!recordModal) return;
        recordModal.hidden = true;
        recordModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        currentRecordRow = null;
      }

      if (recordModal) {
        recordModal.querySelectorAll('[data-modal-close]').forEach(function (el) {
          el.addEventListener('click', closeRecordModal);
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !recordModal.hidden) closeRecordModal();
        });
      }

      document.querySelectorAll('table#useBy td.record a').forEach(function (link) {
        link.addEventListener('click', function (event) {
          if (!recordModal) return;
          event.preventDefault();
          var tr = link.closest('tr');
          var idMatch = (link.getAttribute('href') || '').match(/artifact_id=(\d+)/);
          var artifactId = idMatch ? idMatch[1] : null;
          var titleAnchor = tr ? tr.querySelector('td.name a') : null;
          var artifactName = titleAnchor ? titleAnchor.textContent.trim() : '';
          if (!artifactId) return;
          openRecordModal(artifactId, artifactName, tr);
        });
      });

      if (recordForm) {
        recordForm.addEventListener('submit', function (event) {
          event.preventDefault();
          if (modalSaveBtn) { modalSaveBtn.disabled = true; modalSaveBtn.textContent = 'Saving…'; }
          fetch(recordForm.action, {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(recordForm),
          })
            .then(function (response) {
              return response.json().then(function (data) { return { ok: response.ok, data: data }; });
            })
            .then(function (result) {
              if (result.ok && result.data && result.data.ok) {
                handleRecordSuccess(result.data, currentRecordRow);
                closeRecordModal();
                showToast(result.data.message || 'Interaction recorded.', 'success');
              } else {
                var msg = (result.data && result.data.message) || ('Request failed (HTTP ' + (result.ok ? 'OK' : 'error') + ')');
                showToast(msg, 'error');
                if (modalSaveBtn) { modalSaveBtn.disabled = false; modalSaveBtn.textContent = 'Save'; }
              }
            })
            .catch(function (error) {
              showToast('Network error: ' + error.message, 'error');
              if (modalSaveBtn) { modalSaveBtn.disabled = false; modalSaveBtn.textContent = 'Save'; }
            });
        });

        // Enter saves the interaction (via the AJAX submit above) instead of
        // triggering a native submit / page reload.
        recordForm.addEventListener('keydown', function (event) {
          if (event.key !== 'Enter') return;
          var t = event.target;
          if (!t) return;
          // Notes: let Enter insert a newline.
          if (t.tagName === 'TEXTAREA') return;
          // The "+ New person" sub-form handles its own Enter (creates the person).
          if (t.closest && t.closest('#record-modal-new-user-form')) return;
          // In the interactor search, if suggestions are open Enter picks the top match.
          if (t.id === 'record-modal-user-search') {
            var res = document.getElementById('record-modal-user-results');
            if (res && !res.hidden && res.firstElementChild) {
              event.preventDefault();
              res.firstElementChild.click();
              return;
            }
          }
          event.preventDefault();
          if (typeof recordForm.requestSubmit === 'function') {
            recordForm.requestSubmit();
          } else {
            recordForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          }
        });
      }

      function handleRecordSuccess(data, tr) {
        if (!tr) return;
        var wasOverdue = false;
        var overdueCell = tr.querySelector('td.overdue');
        if (overdueCell) wasOverdue = overdueCell.textContent.trim() === 'Yes';

        if (!data.is_overdue) {
          if (typeof table !== 'undefined' && table) {
            table.row(tr).remove().draw(false);
          } else {
            tr.remove();
          }
          if (wasOverdue) {
            var overdueSpan = document.querySelector('span#totalOverdue');
            if (overdueSpan) {
              var n = parseInt(overdueSpan.textContent, 10);
              if (!isNaN(n) && n > 0) overdueSpan.textContent = (n - 1);
            }
          }
          return;
        }

        var useByCell = tr.querySelector('td.useByDate');
        if (useByCell && data.new_use_by_date) useByCell.textContent = data.new_use_by_date;
        var recentCell = tr.querySelector('td.mostRecentUse');
        if (recentCell) recentCell.textContent = data.most_recent_use_date || '—';
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
                if (wasOverdue && overdueSpan) {
                  var n = parseInt(overdueSpan.textContent, 10);
                  if (!isNaN(n) && n > 0) overdueSpan.textContent = (n - 1);
                }
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

    // Record-interaction modal: add additional interactors (existing or brand-new).
    (function () {
      var usersWrap = document.getElementById('record-modal-users');
      var search = document.getElementById('record-modal-user-search');
      var results = document.getElementById('record-modal-user-results');
      var newToggle = document.getElementById('record-modal-new-user-toggle');
      var newForm = document.getElementById('record-modal-new-user-form');
      var newFirst = document.getElementById('record-modal-new-first');
      var newLast = document.getElementById('record-modal-new-last');
      var newCreate = document.getElementById('record-modal-new-create');
      var newCancel = document.getElementById('record-modal-new-cancel');
      var newMsg = document.getElementById('record-modal-new-msg');
      if (!usersWrap || !search) return;

      var API_BASE = 'https://api.' + window.location.host.replace(/^www\./, '');
      var currentUserId = '<?php echo h($_SESSION['user_id'] ?? ''); ?>';
      var csrfToken = (document.querySelector('#record-modal-form input[name="csrf_token"]') || {}).value || '';
      var searchWrap = search.closest('.modal-user-search-wrap');
      var userIndex = 1; // index 0 is the current user (static chip)

      function hideResults() { results.innerHTML = ''; results.hidden = true; }

      function addChip(id, name) {
        if (id) {
          if (usersWrap.querySelector('input[data-player-id="' + id + '"]')) { return; }
        }
        var i = userIndex++;
        var chip = document.createElement('div');
        chip.className = 'modal-user-chip';

        var label = document.createElement('span');
        label.className = 'modal-user-name';
        label.textContent = name;

        var hidId = document.createElement('input');
        hidId.type = 'hidden';
        hidId.name = 'user[' + i + '][id]';
        hidId.value = id;
        hidId.setAttribute('data-player-id', id);

        var hidName = document.createElement('input');
        hidName.type = 'hidden';
        hidName.name = 'user[' + i + '][name]';
        hidName.value = name;

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'modal-user-remove';
        remove.setAttribute('aria-label', 'Remove ' + name);
        remove.innerHTML = '&times;';
        remove.addEventListener('click', function () { chip.remove(); });

        chip.append(label, hidId, hidName, remove);
        usersWrap.appendChild(chip);
      }

      function resetNewForm() {
        newFirst.value = '';
        newLast.value = '';
        newMsg.textContent = '';
        newForm.style.display = 'none';
        newToggle.style.display = '';
      }

      // Exposed so opening the modal for a new artifact starts clean.
      window.recordModalResetUsers = function () {
        usersWrap.querySelectorAll('.modal-user-chip:not([data-user-index="0"])').forEach(function (c) { c.remove(); });
        userIndex = 1;
        search.value = '';
        hideResults();
        resetNewForm();
      };

      search.addEventListener('input', function () {
        var q = search.value.trim();
        if (q === '') { hideResults(); return; }
        fetch(API_BASE + '/users.php', {
          method: 'POST',
          credentials: 'include',
          body: JSON.stringify({ query: q, userid: currentUserId }),
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.authenticated === false) { location.href = '/login.php'; return; }
            results.innerHTML = '';
            var list = (data.users || []).slice(0, 10);
            if (!list.length) { results.hidden = true; return; }
            results.hidden = false;
            list.forEach(function (u) {
              var li = document.createElement('li');
              li.textContent = (u.FirstName + ' ' + u.LastName).trim();
              li.addEventListener('click', function () {
                addChip(u.id, (u.FirstName + ' ' + u.LastName).trim());
                search.value = '';
                hideResults();
                search.focus();
              });
              results.appendChild(li);
            });
          })
          .catch(function () { hideResults(); });
      });

      // Tapping anywhere outside the search box dismisses the results so the
      // "+ New person" button underneath becomes tappable.
      document.addEventListener('pointerdown', function (event) {
        if (results.hidden) { return; }
        if (searchWrap && !searchWrap.contains(event.target)) { hideResults(); }
      });

      function createPerson() {
        var first = newFirst.value.trim();
        var last = newLast.value.trim();
        if (first === '' && last === '') { newMsg.textContent = 'Enter a name.'; return; }
        newCreate.disabled = true;
        newMsg.textContent = 'Creating…';
        var body = new FormData();
        body.append('FirstName', first);
        body.append('LastName', last);
        body.append('csrf_token', csrfToken);
        fetch('/users/new.php', {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          body: body,
        })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            newCreate.disabled = false;
            if (res.ok && res.d && res.d.ok) {
              addChip(res.d.id, res.d.FullName);
              resetNewForm();
            } else {
              newMsg.textContent = (res.d && res.d.message) || 'Could not create person.';
            }
          })
          .catch(function (err) { newCreate.disabled = false; newMsg.textContent = 'Error: ' + err.message; });
      }

      newToggle.addEventListener('click', function () {
        newForm.style.display = 'flex';
        newToggle.style.display = 'none';
        newFirst.focus();
      });
      newCancel.addEventListener('click', resetNewForm);
      newCreate.addEventListener('click', createPerson);
      [newFirst, newLast].forEach(function (el) {
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); createPerson(); }
        });
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
  </script>

  <a href="https://www.flaticon.com/free-icons/copy" title="copy icons">Copy icons created by Anggara - Flaticon</a>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

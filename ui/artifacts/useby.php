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
<script defer src="useby.js?v=8"></script>

<?php // process form submission and initialize variables
  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);

  $user_id = $_SESSION['user_id'];
  $sweetSpot = $_POST['sweetSpot'] ?? '';
  $minimumAge = $_POST['minimumAge'] ?? 0;
  $shelfSort = $_POST['shelfSort'] ?? 'no';
  $showAttributes = $_POST['showAttributes'] ?? 'no';
  $showInterval = $_POST['showInterval'] ?? 'no';
  // Hide snoozed items by default, and remember the user's last choice
  // across future page loads (as the Type filter remembers its selection).
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hideSnoozed = $_POST['hideSnoozed'] ?? 'no';
  } else {
    $hideSnoozed = $_SESSION['hideSnoozed'] ?? 'yes';
  }
  $_SESSION['hideSnoozed'] = $hideSnoozed;
  $preferences = (new Preferences($db, (int) $user_id))->get();
  $default_use_interval = $preferences['default_use_interval'];
  $interval = $_POST['interval'] ?? $default_use_interval;
  $artifacts = (new UseByQueue($db, (int) $user_id))->entries([
    'default_interval' => $interval,
    'type_ids' => $type_filter['selected'],
    'sweet_spot' => $sweetSpot,
    'minimum_age' => $minimumAge,
    'include_secondary_collection' => $shelfSort === 'yes',
    'hide_snoozed' => $hideSnoozed === 'yes',
  ]);
  $total_overdue = 0;
?>

<main class="useby-page">

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
  <?php include(SHARED_PATH . '/quick_record_popup.php'); ?>
  <?php } ?>

  <div class="table-scroll">
  <table id="useBy" class="list" data-page-length='100'>
    <thead>
      <tr id="headerRow">
        <th>Name (<?php echo count($artifacts); ?>)</th>
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
      <?php foreach ($artifacts as $artifact) {
        $id = h(u($artifact['id']));
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
              <?php if ($artifact['is_snoozed']) { ?>
                <span class="snoozed-badge" title="Hidden from the dashboard priority queue until this date">Snoozed until <?php echo h($artifact['snoozed_until']); ?></span>
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

          <?php $is_overdue = $artifact['status'] === 'overdue'; ?>

          <td class="useByDate date<?php if ($is_overdue) echo ' overdue-past'; ?>" data-label="Interact by"><?php echo h($artifact['use_by_date'] ?? ''); ?></td>

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
                if ($is_overdue) {
                  echo 'style="color: red;"';
                }
            ?>
            >
            <?php
                if ($is_overdue) {
                  $total_overdue++;
                  echo 'Yes';
                } else {
                  echo 'No';
                }
              ?>
          </td>

          <td class="mostRecentUse date hideOnPrint" data-label="Last interacted">
            <?php echo $artifact['last_use'] !== null ? h($artifact['last_use']) : '—'; ?>
          </td>

          <td class="acquisitionDate" data-label="Tracking start"><?php echo h($artifact['Acq']); ?></td>
          <?php if ($showInterval === 'yes') { ?>
          <td class="interval" data-label="Interval"><?php echo h((string) $artifact['interval']); ?></td>
          <?php } ?>
        </tr>
      <?php } ?>
    </tbody>
  </table>
  </div>

  <script src="<?php echo url_for('/shared/js/record-use-submit.js'); ?>"></script>
  <script src="<?php echo url_for('/shared/js/quick-record.js'); ?>"></script>
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
      var showToast = window.RecordUseSubmit
        ? RecordUseSubmit.toast(document.getElementById('useby-toast'))
        : function (message) { alert(message); };

      var overdueSpan = document.querySelector('span#totalOverdue');

      var recordPopup = document.getElementById('record-modal');
      var quickRecord = recordPopup ? QuickRecord.init(recordPopup, {
        toast: showToast,
        onRecorded: handleRecorded
      }) : null;

      document.querySelectorAll('table#useBy td.record a').forEach(function (link) {
        link.addEventListener('click', function (event) {
          if (!quickRecord) return;
          var idMatch = (link.getAttribute('href') || '').match(/artifact_id=(\d+)/);
          if (!idMatch) return;
          event.preventDefault();
          var tr = link.closest('tr');
          var titleAnchor = tr.querySelector('td.name a');
          quickRecord.open(idMatch[1], titleAnchor ? titleAnchor.textContent.trim() : '', tr);
        });
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
  </script>

  <a href="https://www.flaticon.com/free-icons/copy" title="copy icons">Copy icons created by Anggara - Flaticon</a>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

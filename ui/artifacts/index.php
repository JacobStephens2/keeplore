<?php 
  require_once('../../private/initialize.php');
  global $db;
  require_login_or_guest();
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kept = $_POST['kept'] ?? 'allkeptandnot';
    if (isset($_POST['type'])) {

      if ($_POST['type'] == '1') {
        $type = array('');
      } else {
        $type = $_POST['type'];
      }
    } else {
      $type = array();
    }
  } else {
    if (isset($_GET['kept'])) {
      $kept = db_escape($db, $_GET['kept']);
    } else {
      $kept = 'allkeptandnot';
    }
    if (isset($_GET['type'])) {
      $type = array();
      foreach ((array) $_GET['type'] as $selected_type) {
        $selected_type = db_escape($db, $selected_type);
        if ($selected_type !== '' && $selected_type !== null) {
          $type[] = $selected_type;
        }
      }

    } else {
      require_once(SHARED_PATH . '/artifact_type_array.php');
      global $typesArray;
      $type = $typesArray;
    }
    

  }

  // Surfaced visibility switch shares the panel's kept value. Accept the
  // short switch value ('all') alongside the panel values and fall back to
  // showing everything.
  $kept_aliases = [
    'all' => 'allkeptandnot',
    'allkeptandnot' => 'allkeptandnot',
    'yes' => 'yes',
    'no' => 'no',
    'secondary_only' => 'secondary_only',
  ];
  $kept = $kept_aliases[$kept] ?? 'allkeptandnot';
  $default_use_interval = singleValueQuery("SELECT default_use_interval
    FROM users
    WHERE id = " . $_SESSION['user_id'] . "
  ");

  $interval = $_POST['interval'] ?? $default_use_interval;
  $sweetSpotFilter = $_POST['sweetSpotFilter'] ?? '';
  $showAttributes = $_POST['showAttributes'] ?? 'no';
  $tagFilter = $_POST['tag'] ?? ($_GET['tag'] ?? '');
  $artifact_set = find_artifacts_by_user_id($kept, $type, $interval, $sweetSpotFilter, $tagFilter);
  $artifacts = [];
  while ($row = mysqli_fetch_assoc($artifact_set)) {
    $artifacts[] = $row;
  }
  mysqli_free_result($artifact_set);
  $artifacts = with_item_tags($db, $artifacts, (int) $_SESSION['user_id']);
  $page_title = 'Items';
  if ($kept === 'secondary_only') { $page_title .= ' (Secondary Only)'; }
  include(SHARED_PATH . '/header.php'); 
  include(SHARED_PATH . '/dataTable.html');

?>

<script defer src="/shared/filter_button.js"></script>

<main class="items-page">
  <div class="objects listing">

    <header class="page-header page-header-row">
      <div>
        <p class="section-label">Collection</p>
        <h1>Items<?php if ($kept === 'secondary_only') { echo ' (secondary only)'; } ?></h1>
        <p class="page-lede">Everything you track, filterable by type and attributes.</p>
      </div>
      <div class="page-header-actions">
        <a class="prominent-link" href="<?php echo url_for('/artifacts/new'); ?>">Create item <kbd>n</kbd></a>
        <button type="button" id="display_filters">Show filters</button>
      </div>
    </header>

    <?php
      // Surfaced kept-visibility switch: mirrors the panel's kept value,
      // applies immediately via GET, and preserves the type and sweet-spot
      // selections (plus attribute visibility and interval).
      $switch_base = [];
      $switch_type_ids = [];
      if (isset($type) && is_array($type)) {
        foreach (array_values($type) as $type_id) {
          if ($type_id !== '' && $type_id !== null) {
            $switch_type_ids[] = $type_id;
          }
        }
      }
      if (!empty($switch_type_ids)) {
        $switch_base['type'] = array_combine($switch_type_ids, $switch_type_ids);
      }
      if ($sweetSpotFilter !== '') {
        $switch_base['sweetSpotFilter'] = $sweetSpotFilter;
      }
      if ($tagFilter !== '') {
        $switch_base['tag'] = $tagFilter;
      }
      if ($showAttributes === 'yes') {
        $switch_base['showAttributes'] = 'yes';
      }
      if (isset($interval) && (string) $interval !== (string) $default_use_interval) {
        $switch_base['interval'] = $interval;
      }
      $kept_switch_options = [
        'all' => 'All',
        'yes' => 'Kept',
        'no' => 'Not kept',
      ];
      // Secondary-only has no switch option (it stays in the panel), so no
      // option highlights while it is active.
      $kept_switch_active = $kept === 'secondary_only' ? null : $kept;
    ?>
    <nav class="kept-switch" aria-label="Kept visibility">
      <?php foreach ($kept_switch_options as $switch_value => $switch_label) { ?>
        <a href="<?php echo h(url_for('/artifacts/index.php?' . http_build_query(array_merge($switch_base, ['kept' => $switch_value])))); ?>"
          <?php if ($kept_switch_active === $switch_value) { echo 'aria-current="true"'; } ?>
          >
          <?php echo h($switch_label); ?>
        </a>
      <?php } ?>
    </nav>

    <form class="filter-panel" action="<?php echo url_for('/artifacts/index.php'); ?>"
      method="post"
      style="display: none"
      >
      <?php echo csrf_input(); ?>
      <section id="kept">
        <style>
          section#kept label,
          section#kept input {
            display: inline;
          }
        </style>

        <div>
          <label for="allkeptandnot">Show All Items</label>
          <input type="radio" name="kept" value="allkeptandnot" id="allkeptandnot"
          <?php 
            if ($kept === 'allkeptandnot') {
              echo ' checked ';
            }
          ?>
          >
        </div>

        <div>
          <label for="onlykept">Show Only Items Kept</label>
          <input type="radio" name="kept" value="yes" id="onlykept"
            <?php 
            if ($kept === 'yes') {
              echo ' checked ';
            }
            ?>
          >
        </div>

        <div>
          <label for="notkept">Show Only Items Not Kept</label>
          <input type="radio" name="kept" value="no" id="notkept"
          <?php 
            if ($kept === 'no') {
              echo ' checked ';
            }
          ?>
          >
        </div>
        
        <div>
          <label for="secondary_only">Show Secondary Collection Only</label>
          <input type="radio" name="kept" value="secondary_only" id="secondary_only"
          <?php 
            if ($kept === 'secondary_only') {
              echo ' checked ';
            }
          ?>
          >
        </div>

      </section>

      <label for="sweetSpotFilter">Sweet Spot (SwS)</label>
      <input type="text" id="sweetSpotFilter" name="sweetSpotFilter"
        <?php 
          if ($sweetSpotFilter !== '') {
            echo 'value="' . h($sweetSpotFilter) . '"';
          }
        ?>
      >

      <label for="tag">Tag</label>
      <input type="text" id="tag" name="tag" placeholder="beach-safe"
        <?php
          if ($tagFilter !== '') {
            echo 'value="' . h($tagFilter) . '"';
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

      <?php
        // The type list stays collapsed unless a type filter is active, i.e.
        // the current selection differs from the full type set.
        require_once(SHARED_PATH . '/artifact_type_array.php');
        global $typesArray;
        $all_type_ids = array_map('strval', array_values($typesArray ?? []));
        $current_type_ids = [];
        if (isset($type) && is_array($type)) {
          foreach (array_values($type) as $type_id) {
            if ($type_id !== '' && $type_id !== null) {
              $current_type_ids[] = (string) $type_id;
            }
          }
        }
        sort($all_type_ids);
        sort($current_type_ids);
        $type_filter_active = !empty($current_type_ids) && $current_type_ids !== $all_type_ids;
        $type_filter_shortcuts = 'trimmed';
      ?>
      <section id="artifactType" class="type-chip-group">
        <details <?php if ($type_filter_active) { echo 'open'; } ?>>
          <summary>Item type<?php if ($type_filter_active) { echo ' (filtering)'; } ?></summary>
          <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
        </details>
      </section>

      <input type="submit" value="Submit" />
    </form>

    <div class="table-scroll">
  	<table class="list" id="artifacts" data-page-length='100'>
      <thead>
        <tr id="headerRow">
          <th>Kept</th>
          <th>Type</th>
          <th>Tags</th>
          <th>Name (<?php echo count($artifacts); ?>)</th>
          <th>Tracking Start</th>
          <th>Recent Interaction</th>
          <th>Interact By</th>
          <?php
            if ($showAttributes === 'yes') {
              ?>
              <th>SwS</th>
              <th>AvgT</th>
              <th class="tooltip" title="Candidate">Candidate</th>
              <?php
            }
          ?>
        </tr>
      </thead>

      <style>
        .tooltip:hover {
          background: black;
        }
      </style>

      <tbody>
        <?php foreach ($artifacts as $artifact) { ?>
          <tr>
            <?php $row_is_kept = artifact_is_kept($artifact); ?>
            <td class="kept" data-artifact-id="<?php echo h($artifact['id']); ?>" data-kept="<?php echo $row_is_kept ? '1' : '0'; ?>">
              <?php if (is_guest()) { ?>
                <?php echo $row_is_kept ? 'yes' : 'no'; ?>
              <?php } else { ?>
                <form method="post" action="<?php echo url_for('/artifacts/set-tracked.php'); ?>" class="kept-toggle-form" style="margin:0;">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="artifact_id" value="<?php echo h($artifact['id']); ?>">
                  <input type="hidden" name="artifact_name" value="<?php echo h($artifact['Title']); ?>">
                  <input type="hidden" name="value" value="<?php echo $row_is_kept ? '0' : '1'; ?>">
                  <input type="hidden" name="return_to" value="index">
                  <button type="submit" class="kept-toggle-btn" aria-pressed="<?php echo $row_is_kept ? 'true' : 'false'; ?>">
                    <?php echo $row_is_kept ? 'Kept' : 'Keep'; ?>
                  </button>
                </form>
              <?php } ?>
            </td>

            <td><?php echo h($artifact['type']); ?></td>

            <td><?php echo h(implode(', ', $artifact['tags'] ?? [])); ?></td>

            <td class="artifact_title">
              <a class="table-action"
                href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($artifact['id']))); ?>"
                >
                <?php echo h($artifact['Title']); ?>
              </a>
            </td>

            <td class="date acquisition"><?php echo h($artifact['Acq']); ?></td>
            
            <td class="date most_recent_use">
              <?php 
                if ($artifact['MaxPlay'] === NULL && $artifact['MaxUse'] === NULL) {
                  $most_recent_use = ''; 
                } elseif ($artifact['MaxPlay'] > $artifact['MaxUse']) {
                  $most_recent_use = $artifact['MaxPlay']; 
                } else {
                  $most_recent_use = $artifact['MaxUse']; 
                }
                echo h($most_recent_use);
              ?>
            </td>

            <td class="date use_by"
              <?php 
                if ($most_recent_use === '') {
                  $conditional_interval = floor($interval);
                  $starting_date = $artifact['Acq'];
                } else {
                  $conditional_interval = floor($interval * 2);
                  $starting_date = $most_recent_use;
                }
                $use_by = date("Y-m-d", strtotime("$starting_date + $conditional_interval days"));
                
                if ($use_by < date('Y-m-d') && artifact_is_kept($artifact)) {
                  echo " style='color:red;' ";
                }; 
              ?>
              >
              <?php 
                if ($use_by !== '1970-01-01') {
                  echo h($use_by); 
                }
              ?>
            </td>

            <?php // show other attributes conditionally
              if ($showAttributes === 'yes') {
                ?>
                <td>
                  <?php echo $artifact['ss']; ?>
                </td>
    
                <td>
                  <?php 
                    $avg_time = ($artifact['mnt'] + $artifact['mxt']) / 2;
                    echo h(ceil($avg_time)); 
                  ?>
                </td>

                <td>
                  <?php 
                  
                  if ($artifact['Candidate'] != '' && $artifact['Candidate'] != 0) { 
                    echo 'Yes'; 
                  } else {
                    echo 'No';
                  }
                  ?>
                </td>
                <?php
              }
            ?>
            
          </tr>
        <?php } ?>
      </tbody>
  	</table>
    </div>



    <div id="items-toast" class="toast" role="status" aria-live="polite"></div>

    <script>
      // Row Keep/Kept toggle: reuses the JSON kept-toggle endpoint with
      // toast confirmation. The row keeps its place with updated state;
      // nothing reloads.
      (function () {
        var toastEl = document.getElementById('items-toast');
        var toastTimer = null;
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
        document.querySelectorAll('.kept-toggle-form').forEach(function (form) {
          form.addEventListener('submit', function (event) {
            event.preventDefault();
            var cell = form.closest('td.kept');
            var button = form.querySelector('.kept-toggle-btn');
            var valueInput = form.querySelector('input[name="value"]');
            button.disabled = true;
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
                  var isKept = result.data.is_kept === 1;
                  cell.dataset.kept = isKept ? '1' : '0';
                  button.textContent = isKept ? 'Kept' : 'Keep';
                  button.setAttribute('aria-pressed', isKept ? 'true' : 'false');
                  valueInput.value = isKept ? '0' : '1';
                  showToast(result.data.message || 'Updated.', 'success');
                } else {
                  var msg = (result.data && result.data.message) || 'Request failed';
                  showToast(msg, 'error');
                }
                button.disabled = false;
              })
              .catch(function (error) {
                showToast('Network error: ' + error.message, 'error');
                button.disabled = false;
              });
          });
        });
      })();
    </script>

    <script src="<?php echo url_for('/artifacts/items-table-sort.js'); ?>?v=1"></script>
    <script class="data_table">
      const itemsTableHeaders = Array.prototype.map.call(
        document.querySelectorAll('#artifacts thead th'),
        function (th) { return th.textContent; }
      );
      const itemsTableSortFallback = [
        { column: 'Tracking Start', dir: 'desc' },
        { column: 'Recent Interaction', dir: 'desc' },
        { column: 'Interact By', dir: 'desc' },
      ];
      let table = new DataTable('#artifacts', {
        order: KeeploreItemsTableSort.restore(
          itemsTableHeaders,
          window.localStorage,
          itemsTableSortFallback
        ),
      });
      table.on('order', function () {
        KeeploreItemsTableSort.persist(
          itemsTableHeaders,
          window.localStorage,
          table.order()
        );
      });

      const itemsSearch = document.querySelector('#artifacts_filter input')
        || document.querySelector('.dataTables_wrapper .dataTables_filter input');
      if (itemsSearch) {
        itemsSearch.setAttribute('data-shortcut', 'items-search');
        itemsSearch.focus();
        itemsSearch.addEventListener('keydown', function(event) {
          if (event.key === 'Escape') {
            itemsSearch.blur();
          }
        });
      }

      const createItemUrl = <?php echo json_encode(url_for('/artifacts/new')); ?>;
      document.addEventListener('keydown', function(event) {
        if (event.key !== 'n' && event.key !== 'N') {
          return;
        }
        if (event.metaKey || event.ctrlKey || event.altKey) {
          return;
        }
        const target = event.target;
        const inEmptyItemsSearch = target
          && target.closest
          && target.closest('.dataTables_filter')
          && target.value === '';
        if (!inEmptyItemsSearch && target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)) {
          return;
        }
        event.preventDefault();
        window.location.href = createItemUrl;
      });

      document.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
          if (event.target && event.target.closest('.dataTables_filter')) {
            return;
          }
          event.preventDefault();
          document.querySelector('form').submit();
        }
      })
    </script>
  </div>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

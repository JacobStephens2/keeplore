<?php
  require_once dirname(__DIR__, 2) . '/private/initialize.php';
  require_once dirname(__DIR__, 2) . '/private/items_list.php';
  global $db;
  require_login_or_guest();

  list($default_use_interval, $typesArray) = items_list_load_filter_defaults($_SESSION['user_id']);

  $filters = items_list_filters_from_request(
    $_GET,
    $_POST,
    $_SERVER['REQUEST_METHOD'],
    $default_use_interval,
    $typesArray
  );
  $kept = $filters['kept'];
  $type = $filters['type'];
  $interval = $filters['interval'];
  $players = $filters['players'];
  $showAttributes = $filters['showAttributes'];
  $tagFilter = $filters['tagFilter'];

  $best_at_heading = items_list_best_at_heading($players);
  $show_players_column = $players !== null || $showAttributes === 'yes';
  $column_count = 7 + ($show_players_column ? 1 : 0) + ($showAttributes === 'yes' ? 2 : 0);

  $page_title = $best_at_heading ?? 'Items';
  if ($kept === 'secondary_only') { $page_title .= ' (Secondary Only)'; }
  include(SHARED_PATH . '/header.php');
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

    <label class="list-search-wrap">
      <span class="sr-only">Search items</span>
      <input type="search" id="items-search" class="list-search" data-shortcut="search" placeholder="Search by name" autocomplete="off" spellcheck="false" autofocus>
    </label>
    <script>
      (function () {
        var search = document.getElementById('items-search');
        var createUrl = <?php echo json_encode(url_for('/artifacts/new')); ?>;
        document.addEventListener('keydown', function (event) {
          if (event.key !== 'n' && event.key !== 'N') {
            return;
          }
          if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
          }
          var target = event.target;
          var inEmptyItemsSearch = search && target === search && target.value === '';
          if (
            !inEmptyItemsSearch &&
            target &&
            (target.tagName === 'INPUT' ||
              target.tagName === 'TEXTAREA' ||
              target.tagName === 'SELECT' ||
              target.isContentEditable)
          ) {
            return;
          }
          event.preventDefault();
          window.location.href = createUrl;
        });
        if (search) {
          search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
              search.blur();
            }
          });
        }
      })();
    </script>

    <?php
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
      if ($players !== null) {
        $switch_base['players'] = $players;
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
      $kept_switch_active = $kept === 'secondary_only' ? null : ($kept === 'allkeptandnot' ? 'all' : $kept);
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

    <?php
      // The picker is a plain GET form, so a count can be bookmarked. It carries
      // the other filters as hidden fields, so choosing a count keeps them.
      $picker_carry = $switch_base;
      unset($picker_carry['players']);
      if ($kept_switch_active !== null) {
        $picker_carry['kept'] = $kept_switch_active;
      } elseif ($kept === 'secondary_only') {
        $picker_carry['kept'] = 'secondary_only';
      }
    ?>
    <form class="player-picker" method="get" action="<?php echo url_for('/artifacts/index.php'); ?>">
      <label>Best at <input type="number" name="players" min="1" inputmode="numeric"
        value="<?php echo $players === null ? '' : h((string) $players); ?>"> players</label>
      <?php foreach ($picker_carry as $carry_name => $carry_value) {
        foreach ((array) $carry_value as $carry_key => $carry_item) {
          $carry_field = is_array($carry_value) ? $carry_name . '[' . $carry_key . ']' : $carry_name; ?>
        <input type="hidden" name="<?php echo h($carry_field); ?>" value="<?php echo h((string) $carry_item); ?>">
      <?php } } ?>
      <button type="submit">Show</button>
      <?php if ($players !== null) { ?>
        <a class="all-counts" href="<?php echo h(url_for('/artifacts/index.php' . ($picker_carry ? '?' . http_build_query($picker_carry) : ''))); ?>">All counts</a>
      <?php } ?>
    </form>

    <?php if ($best_at_heading !== null) { ?>
      <h2 class="best-at-heading"><?php echo h($best_at_heading); ?></h2>
      <p class="best-at-lede">Items whose sweet spot includes <?php echo h((string) $players); ?>, with each one's player range and sweet spot.</p>
    <?php } ?>

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

      <?php if ($players !== null) { ?>
        <input type="hidden" name="players" value="<?php echo h((string) $players); ?>">
      <?php } ?>

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

    <noscript>
      <p>The items list needs JavaScript.</p>
    </noscript>

    <div class="table-scroll">
  	<table class="list" id="artifacts" data-page-length='100'>
      <thead>
        <tr id="headerRow">
          <th data-sort="is_kept">Kept</th>
          <th data-sort="title" id="items-name-header">Name</th>
          <?php if ($show_players_column) { ?>
            <th data-sort="players">Players</th>
          <?php } ?>
          <th data-sort="type">Type</th>
          <th data-sort="tags">Tags</th>
          <th data-sort="acq">Tracking Start</th>
          <th data-sort="most_recent_use">Recent Interaction</th>
          <th data-sort="use_by">Interact By</th>
          <?php
            if ($showAttributes === 'yes') {
              ?>
              <th data-sort="avg_time">AvgT</th>
              <th class="tooltip" data-sort="candidate" title="Candidate">Candidate</th>
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

      <tbody id="items-list-body">
        <tr class="list-status">
          <td colspan="<?php echo $column_count; ?>">Loading items…</td>
        </tr>
      </tbody>
  	</table>
    </div>
    <div id="items-list-pager" class="list-pager"></div>

    <div id="items-toast" class="toast" role="status" aria-live="polite"></div>

    <script src="<?php echo url_for('/shared/js/list-table.js'); ?>?v=1"></script>
    <script src="<?php echo url_for('/artifacts/items-table-sort.js'); ?>?v=1"></script>
    <script type="application/json" id="items-list-config"><?php
      echo json_encode([
        'dataUrl' => url_for('/artifacts/items-data.php') . '?' . http_build_query(items_list_query_params($filters, $typesArray ?? [])),
        'itemUrlPrefix' => url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id='),
        'keptToggleUrl' => url_for('/artifacts/set-tracked.php'),
        'csrfToken' => generate_csrf_token(),
        'isGuest' => is_guest(),
        'showAttributes' => $showAttributes === 'yes',
        'showPlayers' => $show_players_column,
        'columnCount' => $column_count,
        'emptyMessage' => $players === null ? 'No items yet.' : 'No items are ' . lcfirst($best_at_heading) . '.',
        'pageLength' => 100,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    ?></script>
    <script src="/shared/js/items-list.js?v=6"></script>
  </div>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

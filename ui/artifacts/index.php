<?php
  require_once dirname(__DIR__, 2) . '/private/initialize.php';
  require_once dirname(__DIR__, 2) . '/private/items_list.php';
  global $db;
  require_login_or_guest();

  list($default_use_interval, $type_ids_by_name) = items_list_load_filter_defaults($_SESSION['user_id']);

  $filters = items_list_filters_from_request(
    $_GET,
    $_POST,
    $_SERVER['REQUEST_METHOD'],
    $default_use_interval,
    $type_ids_by_name
  );
  $kept = $filters['kept'];
  $type = $filters['type'];
  $players = $filters['players'];
  $age = $filters['age'];
  $age_unknown = $filters['ageUnknown'];
  $showAttributes = $filters['showAttributes'];
  $tagFilter = $filters['tagFilter'];

  $filter_heading = items_list_heading($players, $age);
  $bgg_reviewers = (new BggRatings($db, (int) $_SESSION['user_id']))->reviewers();
  $columns = items_list_columns($filters, $bgg_reviewers);

  $page_title = $filter_heading ?? 'Items';
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
      // Every link and carried field keeps the filters it doesn't change.
      $filter_query = fn (array $changes = []) => items_list_filter_query($filters, $type_ids_by_name, $default_use_interval, $changes);
      $items_url = fn (array $query) => url_for('/artifacts/index.php' . ($query ? '?' . http_build_query($query) : ''));
      $kept_switch_options = [
        'all' => 'All',
        'yes' => 'Kept',
        'no' => 'Not kept',
      ];
      $kept_switch_active = $kept === 'secondary_only' ? null : ($kept === 'allkeptandnot' ? 'all' : $kept);
    ?>
    <nav class="kept-switch" aria-label="Kept visibility">
      <?php foreach ($kept_switch_options as $switch_value => $switch_label) { ?>
        <a href="<?php echo h($items_url($filter_query(['kept' => $switch_value === 'all' ? null : $switch_value]))); ?>"
          <?php if ($kept_switch_active === $switch_value) { echo 'aria-current="true"'; } ?>
          >
          <?php echo h($switch_label); ?>
        </a>
      <?php } ?>
    </nav>

    <?php
      // One click to games only, or to Other (items still waiting for a real
      // type). Each link keeps every other filter.
      $type_switch = items_list_type_switch($type_ids_by_name, $type);
    ?>
    <nav class="kept-switch type-switch" aria-label="Item type">
      <?php foreach ($type_switch['options'] as $switch_value => $switch_option) { ?>
        <a href="<?php echo h($items_url($filter_query(['type' => $switch_value === 'all' ? null : $switch_option['type_ids']]))); ?>"
          <?php if ($type_switch['active'] === $switch_value) { echo 'aria-current="true"'; } ?>
          >
          <?php echo h($switch_option['label']); ?>
        </a>
      <?php } ?>
    </nav>

    <?php
      // The picker is a plain GET form, so a count or age can be bookmarked. It
      // carries the other filters as hidden fields, so choosing one keeps them.
      // Clear is the same query: the other filters with no count or age.
      $picker_carry = $filter_query(['players' => null, 'age' => null, 'ageUnknown' => null]);
    ?>
    <form class="player-picker" method="get" action="<?php echo url_for('/artifacts/index.php'); ?>">
      <label>Best at <input type="number" name="players" min="1" inputmode="numeric"
        value="<?php echo $players === null ? '' : h((string) $players); ?>"> players</label>
      <label title="Shows items recommended for this age or younger.">Youngest age <input type="number" name="age" min="1" inputmode="numeric"
        value="<?php echo $age === null ? '' : h((string) $age); ?>"></label>
      <label title="With a youngest age, also show items that have no recorded minimum age."><input type="checkbox" name="age_unknown" value="yes"
        <?php if ($age_unknown) { echo 'checked'; } ?>> Include unknown ages</label>
      <?php foreach (items_list_hidden_fields($picker_carry) as [$carry_name, $carry_value]) { ?>
        <input type="hidden" name="<?php echo h($carry_name); ?>" value="<?php echo h($carry_value); ?>">
      <?php } ?>
      <button type="submit">Show</button>
      <?php if ($players !== null || $age !== null) { ?>
        <a class="all-counts" href="<?php echo h($items_url($picker_carry)); ?>">Clear</a>
      <?php } ?>
    </form>

    <?php if ($filter_heading !== null) { ?>
      <h2 class="best-at-heading"><?php echo h($filter_heading); ?></h2>
      <?php if ($players !== null) { ?>
        <p class="best-at-lede">Items whose sweet spot includes <?php echo h((string) $players); ?>, with each one's player range and sweet spot.</p>
      <?php } ?>
      <?php if ($age !== null) { ?>
        <p class="best-at-lede">Items recommended for age <?php echo h((string) $age); ?> or younger.
          <?php echo $age_unknown ? 'Items with no recorded minimum age are included, with a blank Age.' : 'Items with no recorded minimum age are left out.'; ?></p>
      <?php } ?>
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

      <?php // The panel sets what it shows and carries every other filter.
        foreach (items_list_hidden_fields($filter_query(ITEMS_LIST_FILTER_PANEL_CHANGES)) as [$carry_name, $carry_value]) { ?>
        <input type="hidden" name="<?php echo h($carry_name); ?>" value="<?php echo h($carry_value); ?>">
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
        // Both id lists are in the owner's Type order, so they compare as is.
        $current_type_ids = type_filter_owned($type_ids_by_name, $type ?? []);
        $type_filter = ['types' => $type_ids_by_name, 'selected' => $current_type_ids];
        $type_filter_active = !empty($current_type_ids) && $current_type_ids !== type_filter_owned($type_ids_by_name, $type_ids_by_name);
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

    <p id="items-sort-summary" class="list-sort-summary" aria-live="polite"></p>
    <p class="list-sort-hint">To sort by two columns, click the first heading (Tags), then Shift-click the second (Gyges), or pick it from Then by.</p>
    <div class="table-scroll">
  	<table class="list" id="artifacts" data-page-length='100'>
      <thead>
        <tr id="headerRow">
          <?php foreach ($columns as $column) { ?>
            <th data-sort="<?php echo h($column['key']); ?>"<?php
              if ($column['key'] === 'title') { echo ' id="items-name-header"'; }
              if ($column['tooltip'] !== '') { echo ' title="' . h($column['tooltip']) . '"'; }
            ?>><?php echo h($column['label']); ?></th>
          <?php } ?>
        </tr>
      </thead>

      <style>
        .tooltip:hover {
          background: black;
        }
      </style>

      <tbody id="items-list-body">
        <tr class="list-status">
          <td colspan="<?php echo count($columns); ?>">Loading items…</td>
        </tr>
      </tbody>
  	</table>
    </div>
    <div id="items-list-pager" class="list-pager"></div>

    <div id="items-toast" class="toast" role="status" aria-live="polite"></div>

    <dialog id="bgg-rating-dialog" class="modal-panel bgg-rating-dialog" aria-labelledby="bgg-rating-dialog-title">
      <form method="dialog">
        <button type="submit" class="modal-close" aria-label="Close">&times;</button>
      </form>
      <h2 id="bgg-rating-dialog-title" class="modal-title"></h2>
      <p id="bgg-rating-dialog-score" class="modal-subtitle"></p>
      <p id="bgg-rating-dialog-comment" class="bgg-rating-comment"></p>
      <a id="bgg-rating-dialog-link" class="modal-link" target="_blank" rel="noopener">On BoardGameGeek</a>
    </dialog>

    <script src="<?php echo url_for('/shared/js/list-table.js'); ?>?v=3"></script>
    <script src="<?php echo url_for('/artifacts/items-table-sort.js'); ?>?v=1"></script>
    <script type="application/json" id="items-list-config"><?php
      echo json_encode([
        'dataUrl' => url_for('/artifacts/items-data.php') . '?' . http_build_query($filter_query()),
        'itemUrlPrefix' => url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id='),
        'keptToggleUrl' => url_for('/artifacts/set-tracked.php'),
        'csrfToken' => generate_csrf_token(),
        'isGuest' => is_guest(),
        'columns' => array_column($columns, 'key'),
        'emptyMessage' => $filter_heading === null ? 'No items yet.' : 'No items are ' . lcfirst($filter_heading) . '.',
        'pageLength' => 100,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    ?></script>
    <script src="/shared/js/items-list.js?v=15"></script>
  </div>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

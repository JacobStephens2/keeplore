<?php
  require_once('../../private/initialize.php');
  require_once(PRIVATE_PATH . '/item_interactions.php');
  require_login_or_guest();
  $page = (new ItemInteractionsPage($db, (int) $_SESSION['user_id']))->read(
    $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION
  );
  $type_filter = $page['type_filter'];
  $filters = $page['filters'];
  $date_hint = $page['date_hint'];
  $table = $page['table'];
  $heading_count = $table['heading_count'] ?? null;
  $guest = is_guest();
  $page_title = 'Item Interactions';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');
?>

<script defer src="/shared/filter_button.js"></script>

<main>
    <header class="page-header page-header-row">
      <div>
        <p class="section-label">History</p>
        <h1><?php echo h($page_title); ?><?php if ($heading_count !== null) { echo ' (' . h($heading_count) . ' group settings)'; } ?></h1>
        <p class="page-lede">Recorded uses, filterable by type and date.</p>
      </div>
      <div class="page-header-actions">
        <button type="button" id="display_filters">Show filters</button>
      </div>
    </header>

    <form class="filter-panel" method="POST" style="display: none">
      <?php echo csrf_input(); ?>
      <label for="artifactType">Item type</label>
      <section id="artifactType" class="type-chip-group">
        <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
      </section>

      <label for="minimumDate">Minimum Date (<?php echo h($date_hint['interval']); ?> days ago: <?php echo h($date_hint['cutoff']); ?>)</label>
      <input type="date" name="minimumDate" id="minimumDate" value="<?php echo h($filters['minimumDate']); ?>">

      <label for="showAttributes">Show item attributes</label>
      <input type="hidden" name="showAttributes" value="no">
      <input type="checkbox" name="showAttributes" id="showAttributes" value="yes"<?php if ($filters['showAttributes']) { echo ' checked'; } ?>>

      <label for="hide_duplicate_group_settings">Hide Duplicate Group Settings (Date Agnostic)</label>
      <input name="hide_duplicate_group_settings"
        type="checkbox"
        id="hide_duplicate_group_settings"
        value="yes"
        <?php if ($filters['hide_duplicate_group_settings']) { echo ' checked '; } ?>
      >

      <label for="hide_online_setting">Hide Online Setting</label>
      <input name="hide_online_setting"
        type="checkbox"
        id="hide_online_setting"
        value="yes"
        <?php if ($filters['hide_online_setting']) { echo ' checked '; } ?>
      >

      <button type="submit">Submit</button>
    </form>

    <?php if ($page['error'] !== null) { ?>
    <p class="form-error"><?php echo h($page['error']); ?></p>
    <?php } else {
      $cell_classes = [
        'use_date' => 'date',
        'item_title' => 'title',
        'item_type' => 'type',
        'person_count' => 'user_count',
        'candidate_spot' => 'canidate_spot_used',
      ];
    ?>
    <div class="table-scroll">
      <table class="list" id="uses" data-page-length="100" data-order="<?php echo h(json_encode($table['order'], JSON_THROW_ON_ERROR)); ?>">
        <thead>
          <tr id="headerRow">
            <?php foreach ($table['columns'] as $column => $label) {
              $count = $table['column_counts'][$column] ?? null;
            ?>
              <th<?php if ($column === 'group_setting') { echo ' class="group_setting"'; } ?>><?php
                echo h($label);
                if ($count !== null) { echo ' (' . h($count) . ')'; }
              ?></th>
            <?php } ?>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($table['rows'] as $row) { ?>
            <tr>
              <?php foreach ($table['columns'] as $column => $label) { ?>
                <td class="<?php echo h($cell_classes[$column] ?? $column); ?>">
                  <?php if ($column === 'use_date' && !$guest) { ?>
                    <a class="action" href="<?php echo h(url_for('/uses/record-edit.php?id=' . u($row['id']))); ?>"><?php echo h($row['use_date']); ?></a>
                  <?php } elseif ($column === 'item_title') { ?>
                    <a class="action" href="<?php echo h(url_for('/artifacts/' . ($guest ? 'show' : 'edit') . '.php?id=' . u($row['item_id']))); ?>"><?php echo h($row['item_title']); ?></a>
                  <?php } elseif ($column === 'candidate') {
                    if ($row['candidate']) { echo 'Yes'; }
                  } else {
                    echo h($row[$column]);
                  } ?>
                </td>
              <?php } ?>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>

    <p>Group, setting, and game combinations: <?php echo h($table['group_setting_item_count']); ?></p>
    <p>Group and setting combinations: <?php echo h($table['group_and_setting_count']); ?></p>

    <script>
      new DataTable('#uses');
    </script>
    <?php } ?>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php
  require_once('../../private/initialize.php');
  require_once(PRIVATE_PATH . '/item_interactions.php');
  require_login_or_guest();
  $page_title = 'Item Interactions';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');

  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);

  $minimumDate = $_POST['minimumDate'] ?? '';
  $default_use_interval = (new Preferences($db, (int) $_SESSION['user_id']))->get()['default_use_interval'];
  $interval_cutoff = app_days_before(app_today(), (float) $default_use_interval);
  [$cutoff_year, $cutoff_month, $cutoff_day] = explode('-', $interval_cutoff);
  $interval_label = rtrim(rtrim(number_format((float) $default_use_interval, 2, '.', ''), '0'), '.');
  $showAttributes = $_POST['showAttributes'] ?? 'no';
  $hide_duplicate_group_settings = $_POST['hide_duplicate_group_settings'] ?? 'no';
  $hide_online_setting = $_POST['hide_online_setting'] ?? 'no';

  $uses_array = [];
  $uses_error = null;
  try {
    $uses_array = (new Uses($db, (int) $_SESSION['user_id']))->all([
      'type_ids' => $type_filter['selected'],
      'since' => (string) $minimumDate,
    ]);
  } catch (InvalidArgumentException $invalid) {
    $uses_error = $invalid->getMessage();
  }

  // Candidate, sweet spot and "Candidate spot used" read the owner's Items,
  // loaded once and only when the attributes are shown.
  $items = $showAttributes === 'yes' ? (new Items($db, (int) $_SESSION['user_id']))->list() : [];
?>

<script defer src="/shared/filter_button.js"></script>

<main>
    <header class="page-header page-header-row">
      <div>
        <p class="section-label">History</p>
        <h1><?php echo h($page_title); ?></h1>
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

      <label for="minimumDate">Minimum Date (<?php echo h($interval_label); ?> days ago: <?php echo h($cutoff_month . '/' . $cutoff_day . '/' . $cutoff_year); ?>)</label>
      <input type="date" name="minimumDate" id="minimumDate" value="<?php echo h((string) $minimumDate); ?>">

      <label for="showAttributes">Show item attributes</label>
      <input type="hidden" name="showAttributes" value="no">
      <input type="checkbox" name="showAttributes" id="showAttributes" value="yes"
        <?php
          if ($showAttributes === 'yes') {
            echo ' checked ';
          }
        ?>
      >

      <label for="hide_duplicate_group_settings">Hide Duplicate Group Settings (Date Agnostic)</label>
      <input name="hide_duplicate_group_settings"
        type="checkbox"
        id="hide_duplicate_group_settings"
        value="yes"
        <?php if ($hide_duplicate_group_settings === 'yes') { echo ' checked '; } ?>
      >

      <label for="hide_online_setting">Hide Online Setting</label>
      <input name="hide_online_setting"
        type="checkbox"
        id="hide_online_setting"
        value="yes"
        <?php if ($hide_online_setting === 'yes') { echo ' checked '; } ?>
      >

      <button type="submit">Submit</button>
    </form>

    <?php if ($uses_error !== null) { ?>
    <p class="form-error"><?php echo h($uses_error); ?></p>
    <?php } else {
      $interactions = item_interactions(
        $uses_array,
        $items,
        $hide_online_setting === 'yes',
        $hide_duplicate_group_settings === 'yes'
      );
    ?>
    <div class="table-scroll">
  	<table class="list" id="uses" data-page-length='100'>
      <thead>
        <tr id="headerRow">
          <th>Interaction Date <?php if ($hide_duplicate_group_settings === 'no') { echo '(' . $interactions['use_count'] . ')'; } ?></th>
          <th>Item</th>
          <th class="group_setting">Group Setting</th>
          <th>Type</th>
          <th>Setting</th>
          <?php
            if ($showAttributes === 'yes') {
              ?>
              <th>Candidate</th>
              <th>SwS</th>
              <th>User Count</th>
              <th>Candidate Spot Used</th>
              <?php
            }
          ?>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($interactions['rows'] as $row) { ?>
            <tr>
              <td class="date">
                <?php if (!is_guest()) { ?>
                <a
                  class="action"
                  href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($row['id']))); ?>"
                  >
                  <?php echo h($row['use_date']); ?>
                </a>
                <?php } else { echo h($row['use_date']); } ?>
              </td>

              <td class="title">
                <a
                  class="action"
                  href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($row['item_id']))); ?>"
                  >
                  <?php echo h($row['item_title']); ?>
                </a>
              </td>

              <td class="group_setting">
                <?php echo h($row['group_setting']); ?>
              </td>

              <td class="type">
                <?php echo h($row['item_type']); ?>
              </td>

              <td class="setting">
                <?php echo h($row['setting']); ?>
              </td>

              <?php if ($showAttributes === 'yes') { ?>
                  <td class="candidate">
                    <?php if ($row['candidate']) { echo 'Yes'; } ?>
                  </td>

                  <td class="sweet_spot">
                    <?php echo h($row['sweet_spot']); ?>
                  </td>

                  <td class="user_count">
                    <?php echo $row['person_count']; ?>
                  </td>

                  <td class="canidate_spot_used"><?php echo h($row['candidate_spot']); ?></td>
              <?php } ?>

            </tr>
        <?php } ?>
      </tbody>
  	</table>
    </div>

    <p>Group, setting, and game combinations: <?php echo $interactions['group_setting_game_count']; ?></p>
    <p>Group and setting combinations: <?php echo $interactions['group_and_setting_count']; ?></p>

    <script>


      <?php
      if ($hide_duplicate_group_settings === 'yes' || $hide_online_setting === 'yes') {
        ?>
        document.querySelector('h1').innerText += ' (<?php echo $interactions['group_setting_game_count']; ?> group settings)';
        document.querySelector('.group_setting').innerText += ' (<?php echo $interactions['group_setting_game_count']; ?>)';
        let table = new DataTable('#uses', {
          // options
          order: [
            [ 5, 'desc'], // User group descending
            [ 0, 'desc'] // Most recently used first
          ]
        });
        <?php
      } else {
        ?>
        let table = new DataTable('#uses', {
          // options
          order: [
            [ 0, 'desc'], // Most recently used ascending
            [ 2, 'asc'] // smaller user groups first
          ]
        });
        <?php
      }
      ?>
    </script>
    <?php } ?>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

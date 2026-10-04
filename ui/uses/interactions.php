<?php
  require_once('../../private/initialize.php');
  require_once(PRIVATE_PATH . '/item_facts.php');
  require_login_or_guest();
  $page_title = 'Item Interactions';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');

  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);

  $minimumDate = $_POST['minimumDate'] ?? '';
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
  $total_rows = count($uses_array);

  // Candidate, sweet spot and "Candidate spot used" read the owner's Items,
  // loaded once and only when the attributes are shown.
  $items = $showAttributes === 'yes' ? (new Items($db, (int) $_SESSION['user_id']))->list() : [];
  $items_by_id = array_column($items, null, 'id');
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

      <label for="minimumDate">Minimum Date (<?php echo DEFAULT_USE_INTERVAL; ?> days ago: <?php echo date('m/d/Y', strtotime(DEFAULT_USE_INTERVAL . ' days ago')); ?>)</label>
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
    <?php } else { ?>
    <div class="table-scroll">
  	<table class="list" id="uses" data-page-length='100'>
      <thead>
        <tr id="headerRow">
          <th>Interaction Date <?php if ($hide_duplicate_group_settings === 'no') { echo '(' . $total_rows . ')'; } ?></th>
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
        <?php
          $group_setting_game_array = array();
          $group_and_setting_array = array();

          foreach ($uses_array as $use) {

            // Hide online setting uses
            if ($hide_online_setting === 'yes') {
              if (h($use['setting']) === 'online') {
                continue;
              }
            }

            $players = $use['people'];
            $player_count = count($players);

            $i = 0;
            $situation = '';
            if ($player_count < 10) {
              $situation .= '0';
            }

            $situation .= $player_count . ': ';

            $usersArray = [];
            foreach ($players as $player) {
              $usersArray[$player['id']] = $player['first_name'] . ' ' . $player['last_name'];
            }

            // sort by the key ascending
            ksort($usersArray);

            $i = 0;
            foreach ($usersArray as $user) {
              $i++;
              $situation .= $user;
              if ($i != $player_count) {
                $situation .= ', ';
              }
            }

            if ($use['setting'] != 'online') {
              $situation .= ' at';
            }

            $situation .= ' ' . $use['setting'];

            $group_and_setting = $situation;
            if (!in_array($group_and_setting, $group_and_setting_array)) {
              $group_and_setting_array[] = $group_and_setting;
            }

            $situation .= ' (';
            $situation .= h($use['item_title']);

            $group_setting_game = $situation;

            if (!in_array($group_setting_game, $group_setting_game_array)) {
              $group_setting_game_array[] = $group_setting_game;
            } elseif ($hide_duplicate_group_settings === 'yes') {
              continue;
            }

            $candidate_artifact = '';
            $item = $items_by_id[$use['item_id']] ?? [];
            foreach ($items as $candidate_item) {
              if (str_starts_with(mb_strtolower((string) $candidate_item['Candidate']), mb_strtolower($group_setting_game))) {
                $candidate_artifact = $candidate_item['Title'];
                break;
              }
            }

            $situation .= ' on ' . h(substr($use['use_date'],0,10));
            $situation .= ')';

            ?>
            <tr>
              <td class="date">
                <?php if (!is_guest()) { ?>
                <a
                  class="action"
                  href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($use['id']))); ?>"
                  >
                  <?php echo h(substr($use['use_date'],0,10)); ?>
                </a>
                <?php } else { echo h(substr($use['use_date'],0,10)); } ?>
              </td>

              <td class="title">
                <a
                  class="action"
                  href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($use['item_id']))); ?>"
                  >
                  <?php echo h($use['item_title']); ?>
                </a>
              </td>

              <td class="group_setting">
                <?php echo $situation; ?>
              </td>

              <td class="type">
                <?php echo h($use['item_type']); ?>
              </td>

              <td class="setting">
                <?php echo h($use['setting']); ?>
              </td>

              <?php
                if ($showAttributes === 'yes') {
                  ?>
                  <td class="candidate">
                    <?php
                      if (item_is_candidate($item)) {
                        echo 'Yes';
                      }
                    ?>
                  </td>

                  <td class="sweet_spot">
                    <?php echo h($item['SS'] ?? ''); ?>
                  </td>

                  <td class="user_count">
                    <?php echo $player_count; ?>
                  </td>

                  <td class="canidate_spot_used"><?php echo h($candidate_artifact); ?></td>
                  <?php
                }
              ?>

            </tr>
            <?php
          }
        ?>
      </tbody>
  	</table>
    </div>

    <p>Group, setting, and game combinations: <?php echo count($group_setting_game_array); ?></p>
    <p>Group and setting combinations: <?php echo count($group_and_setting_array); ?></p>

    <script>


      <?php
      if ($hide_duplicate_group_settings === 'yes' || $hide_online_setting === 'yes') {
        ?>
        document.querySelector('h1').innerText += ' (<?php echo count($group_setting_game_array); ?> group settings)';
        document.querySelector('.group_setting').innerText += ' (<?php echo count($group_setting_game_array); ?>)';
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

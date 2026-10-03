<?php
  require_once('../../private/initialize.php');
  require_login_or_guest();
  $page_title = 'Item Interactions';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');

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
      $type = array_column((new Types($db, (int) $_SESSION['user_id']))->all(), 'id', 'name');
    }
  }

  $minimumDate = $_POST['minimumDate'] ?? '';
  $showAttributes = $_POST['showAttributes'] ?? 'no';
  $hide_duplicate_group_settings = $_POST['hide_duplicate_group_settings'] ?? 'no';
  $hide_online_setting = $_POST['hide_online_setting'] ?? 'no';

  $use_set = find_uses_by_user_id($type, $minimumDate);

  // Batch-load all uses into an array
  $uses_array = [];
  $use_ids = [];
  $total_rows = $use_set->num_rows;
  while ($use = mysqli_fetch_assoc($use_set)) {
    $uses_array[] = $use;
    $use_ids[] = $use['useID'];
  }
  mysqli_free_result($use_set);

  // Batch-fetch all players for all uses in ONE query (fixes N+1)
  $players_by_use = [];
  if (!empty($use_ids)) {
    $placeholders = implode(',', array_fill(0, count($use_ids), '?'));
    $player_query = "SELECT uses_players.use_id, players.id, players.FirstName, players.LastName
      FROM uses_players
      LEFT JOIN players ON uses_players.player_id = players.id
      WHERE uses_players.user_id = ?
      AND uses_players.use_id IN ($placeholders)
    ";
    $stmt = mysqli_prepare($db, $player_query);
    $param_types = 's' . str_repeat('s', count($use_ids));
    mysqli_stmt_bind_param($stmt, $param_types, $_SESSION['user_id'], ...$use_ids);
    mysqli_stmt_execute($stmt);
    $players_result = mysqli_stmt_get_result($stmt);
    while ($player = mysqli_fetch_assoc($players_result)) {
      $players_by_use[$player['use_id']][] = $player;
    }
    mysqli_stmt_close($stmt);
  }
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
      <input type="date" name="minimumDate" id="minimumDate" value="<?php echo $minimumDate; ?>">

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
              if (h($use['note']) === 'online') {
                continue;
              }
            }

            $players = $players_by_use[$use['useID']] ?? [];
            $player_count = count($players);

            $i = 0;
            $situation = '';
            if ($player_count < 10) {
              $situation .= '0';
            }

            $situation .= $player_count . ': ';

            $usersArray = [];
            foreach ($players as $player) {
              $usersArray[$player['id']] = $player['FirstName'] . ' ' . $player['LastName'];
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

            if ($use['note'] != 'online') {
              $situation .= ' at';
            }

            $situation .= ' ' . $use['note'];

            $group_and_setting = $situation;
            if (!in_array($group_and_setting, $group_and_setting_array)) {
              $group_and_setting_array[] = $group_and_setting;
            }

            $situation .= ' (';
            $situation .= h($use['Title']);

            $group_setting_game = $situation;

            if (!in_array($group_setting_game, $group_setting_game_array)) {
              $group_setting_game_array[] = $group_setting_game;
            } elseif ($hide_duplicate_group_settings === 'yes') {
              continue;
            }

            $candidate_artifact = '';
            if ($showAttributes === 'yes') {
              $group_setting_game_escaped = db_escape($db, $group_setting_game);
              $query = "SELECT title
                FROM games WHERE candidate LIKE '$group_setting_game_escaped%'
              ";
              $candidate_artifact = singleValueQuery($query);
            }

            $situation .= ' on ' . h(substr($use['use_date'],0,10));
            $situation .= ')';

            ?>
            <tr>
              <td class="date">
                <?php if (!is_guest()) { ?>
                <a
                  class="action"
                  href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($use['useID']))); ?>"
                  >
                  <?php echo h(substr($use['use_date'],0,10)); ?>
                </a>
                <?php } else { echo h(substr($use['use_date'],0,10)); } ?>
              </td>

              <td class="title">
                <a
                  class="action"
                  href="<?php echo url_for('/artifacts/' . (is_guest() ? 'show' : 'edit') . '.php?id=' . h(u($use['gameID']))); ?>"
                  >
                  <?php echo h($use['Title']); ?>
                </a>
              </td>

              <td class="group_setting">
                <?php echo $situation; ?>
              </td>

              <td class="type">
                <?php echo h($use['type']); ?>
              </td>

              <td class="setting">
                <?php echo h($use['note']); ?>
              </td>

              <?php
                if ($showAttributes === 'yes') {
                  ?>
                  <td class="candidate">
                    <?php
                      if (h($use['Candidate']) != '' && h($use['Candidate']) != 0) {
                        echo 'Yes';
                      }
                    ?>
                  </td>

                  <td class="sweet_spot">
                    <?php echo h($use['SwS']); ?>
                  </td>

                  <td class="user_count">
                    <?php echo $player_count; ?>
                  </td>

                  <td class="canidate_spot_used"><?php if ($candidate_artifact != 'No results' && $candidate_artifact != '') {echo "$candidate_artifact";} ?></td>
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

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

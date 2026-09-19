<?php

  require_once('../../private/initialize.php');
  require_login();

  if(!isset($_GET['id'])) {
    redirect_to(url_for('/users/index.php'));
  }
  $id = $_GET['id'];

  $user_id = $_SESSION['user_id'];

  if(is_post_request()) {

    // Merge another player into this one (issue #9, brief 3).
    if(isset($_POST['merge_loser_id'])) {
      $merge_errors = [];
      if(!isset($_POST['merge_confirm']) || $_POST['merge_confirm'] !== 'yes') {
        $merge_errors[] = "Confirm the merge before continuing.";
      } else {
        $merge_result = merge_players($id, $_POST['merge_loser_id'], $user_id);
        if($merge_result === true) {
          $_SESSION['message'] = 'The players were merged successfully.';
          redirect_to(url_for('/users/show.php?id=' . h(u($id))));
        } else {
          $merge_errors = $merge_result;
        }
      }
      $errors = $merge_errors;
      $player = find_player_by_id($id);
    } else {

    // Handle form values sent by new.php
    $player = [];
    $player['id'] = $id ?? '';
    $player['FirstName'] = $_POST['FirstName'] ?? '';
    $player['LastName'] = $_POST['LastName'] ?? '';
    $player['G'] = $_POST['G'] ?? '';
    $player['birth_year'] = $_POST['birth_year'] ?? '';
    $player['thisPlayerIsMe'] = $_POST['thisPlayerIsMe'] ?? '';
    $player['user_id'] = $user_id ?? '';

    $result = update_player($player);
    if($result === true) {
      $_SESSION['message'] = 'The user was updated successfully.';
      redirect_to(url_for('/users/show.php?id=' . $id));
    } else {
      $errors = $result;
    }

  }

  } else {

    $player = find_player_by_id($id);

  }

  $page_title = 'Edit User';
  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html');
?>

<main>

  <div class="object edit">
    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form action="<?php echo url_for('/users/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>

      <label for="FirstName">First Name</label>
      <input 
        type="text" 
        name="FirstName" 
        id="FirstName"
        value="<?php echo h($player['FirstName']); ?>" 
      />

      <label for="LastName">Last Name</label>
      <input 
        type="text" 
        id="LastName"
        name="LastName" 
        value="<?php echo h($player['LastName']); ?>" 
      />

      <label for="Gender">Gender (M, F, or Other)</label>
      <input type="text" id="Gender" name="G" value="<?php echo h($player['G']); ?>" />

      <label for="birth_year">Birth Year</label>
      <input type="number" id="birth_year" name="birth_year" value="<?php echo h($player['birth_year']); ?>" />

      <label for="thisPlayerIsMe">This User Is Me</label>
      <input type="hidden" name="thisPlayerIsMe" value="no">
      <input type="checkbox" name="thisPlayerIsMe" id="thisPlayerIsMe"
        value="yes"
        <?php 
          $stmt_rep = mysqli_prepare($db, "SELECT represents_user_id FROM players WHERE id = ?");
          mysqli_stmt_bind_param($stmt_rep, "i", $id);
          mysqli_stmt_execute($stmt_rep);
          $rep_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_rep));
          mysqli_stmt_close($stmt_rep);
          $userIDThisPlayerIDRepresents = $rep_result['represents_user_id'] ?? null;
          if ($userIDThisPlayerIDRepresents == $_SESSION['user_id']) {
            echo 'checked';
          }
        ?>
      >

      <input type="submit" value="Save Edits" />

    </form>

    <h2>Merge another player into this one</h2>
    <p>
      All of the selected player's recorded interactions move to
      <?php echo h($player['FirstName']) . ' ' . h($player['LastName']); ?>,
      and the selected player is deleted. This cannot be undone.
    </p>

    <form action="<?php echo url_for('/users/edit.php?id=' . h(u($id))); ?>" method="post">
      <?php echo csrf_input(); ?>

      <label for="merge_loser_id">Player to merge in and delete</label>
      <select id="merge_loser_id" name="merge_loser_id">
        <?php
          $merge_candidates = find_players_by_user_id();
          while($candidate = mysqli_fetch_assoc($merge_candidates)) {
            if((int) $candidate['id'] === (int) $id) {
              continue;
            }
            echo "<option value=\"" . h($candidate['id']) . "\">"
              . h($candidate['FirstName'] . ' ' . $candidate['LastName'])
              . "</option>";
          }
          mysqli_free_result($merge_candidates);
        ?>
      </select>

      <label for="merge_confirm">
        <input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes">
        <span id="merge_confirm_text">Yes, merge the selected player into
        <?php echo h($player['FirstName']) . ' ' . h($player['LastName']); ?>
        and delete it</span>
      </label>

      <input type="submit" value="Merge Players" />

    </form>

    <script>
      (function() {
        var loser = document.getElementById('merge_loser_id');
        var text = document.getElementById('merge_confirm_text');
        var survivor = <?php echo json_encode($player['FirstName'] . ' ' . $player['LastName']); ?>;
        function updateMergeConfirm() {
          var name = loser.options[loser.selectedIndex].text;
          text.textContent = 'Yes, merge ' + name + ' into ' + survivor
            + ' and delete ' + name;
        }
        loser.addEventListener('change', updateMergeConfirm);
        updateMergeConfirm();
      })();
    </script>

  </div>

  <section id="uses">
    <?php
      $player_id = (int) $_REQUEST['id'];
      $user_id_int = (int) $user_id;

      $stmt_interactions = mysqli_prepare($db, "SELECT
        uses.id AS use_id,
        DATE(uses.use_date) AS use_date,
        games.id AS artifactID,
        games.Title,
        games.type
        FROM uses_players
        JOIN uses ON uses.id = uses_players.use_id
        JOIN games ON games.id = uses.artifact_id
        WHERE uses_players.user_id = ?
          AND uses_players.player_id = ?
        ORDER BY uses.use_date DESC");
      mysqli_stmt_bind_param($stmt_interactions, "ii", $user_id_int, $player_id);
      mysqli_stmt_execute($stmt_interactions);
      $interactionsResult = mysqli_stmt_get_result($stmt_interactions);
    ?>
    <h2>
      <?php echo $interactionsResult->num_rows; ?>
      <?php echo h($player['FirstName']) . ' ' . h($player['LastName']); ?>
      interactions are recorded
    </h2>

    <table id="useList" data-page-length='100'>
      <thead>
        <tr>
          <th>Interaction Date</th>
          <th>Item</th>
          <th>Type</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($interactionsResult as $row) { ?>
          <tr>
            <td>
              <a href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($row['use_id']))); ?>">
                <?php echo $row['use_date'] ? h($row['use_date']) : 'No date'; ?>
              </a>
            </td>
            <td>
              <a href="<?php echo url_for('/artifacts/edit.php?id=' . h(u($row['artifactID']))); ?>">
                <?php echo h($row['Title']); ?>
              </a>
            </td>
            <td><?php echo h($row['type']); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>

    <script>
      let table = new DataTable('#useList', {
        order: [[ 0, 'desc']]
      });
    </script>
  </section>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php require_once('../../private/initialize.php'); ?>

<?php require_login();

$id = $_GET['id'] ?? '1'; // PHP > 7.0

$response = find_response_by_id($id);

?>

<?php $page_title = 'Show use'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <div class="use show">

    <div class="attributes">
      <dl>
        <dt>Game: <?php echo h($response['Title']); ?></dt>
      </dl>
      <dl>
        <dt>Date of play: <?php echo h($response['PlayDate']); ?></dt>
      </dl>
      <!-- GET variable approach to passing player id from new page to show -->
      <dl>
          <?php
            $people = new People($db, (int) $_SESSION['user_id']);
            $person_name = fn ($id) => h($people->find((int) $id)['name'] ?? '');
            echo '<dt>Player 1: ' . $person_name($_GET['player1'] ?? '') . "</dt></dl>";
            for ($playerNo = 2; $playerNo <= 9 && ($_GET['player' . $playerNo] ?? '') != ''; $playerNo++) {
              echo "
                <dl>
                  <dt>Player " . $playerNo . ": " . $person_name($_GET['player' . $playerNo]) . "</dt>
                </dl>";
            }
          ?>
    </div>
    <br />
    <hr />

  </div>


  </div>

</main>

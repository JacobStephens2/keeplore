<?php
require_once('../../private/initialize.php');

require_login();

if(is_post_request()) {

  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

  $player = [];
  $player['FirstName'] = trim($_POST['FirstName'] ?? '');
  $player['LastName'] = trim($_POST['LastName'] ?? '');
  $player['G'] = $_POST['G'] ?? '';
  if ($player['G'] == '') {
    $player['G'] = 'other';
  }
  $player['birth_year'] = $_POST['birth_year'] ?? '';

  if ($is_ajax && $player['FirstName'] === '' && $player['LastName'] === '') {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Please enter a name.']);
    exit;
  }

  $result = insert_player($player);
  if($result === true) {
    $new_id = mysqli_insert_id($db);

    if ($is_ajax) {
      $fullName = trim($player['FirstName'] . ' ' . $player['LastName']);
      header('Content-Type: application/json');
      echo json_encode([
        'ok' => true,
        'id' => $new_id,
        'FirstName' => $player['FirstName'],
        'LastName' => $player['LastName'],
        'FullName' => $fullName,
      ]);
      exit;
    }

    $_SESSION['message'] = 'The player record was created successfully.';
    redirect_to(url_for('/users/show.php?id=' . $new_id));
  } else {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(500);
      echo json_encode(['ok' => false, 'message' => 'Failed to create person.']);
      exit;
    }
    $errors = $result;
  }

} else {
  // display the blank form
  $player = [];
  $player["FirstName"] = '';
  $player["LastName"] = '';
  $player["G"] = '';
  $player["birth_year"] = '';
}

?>

<?php $page_title = 'Add User'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>


  <div class="object new">
    <h1>Create User Record</h1>

    <?php echo display_errors($errors); ?>

    <form action="<?php echo url_for('/users/new.php'); ?>" method="post">
      <?php echo csrf_input(); ?>
      <dl>
        <dt>First Name</dt>
        <dd><input type="text" name="FirstName" value="<?php echo h($player['FirstName']); ?>" /></dd>
      </dl>
      <dl>
        <dt>Last Name</dt>
        <dd><input type="text" name="LastName" value="<?php echo h($player['LastName']); ?>" /></dd>
      </dl>
      <dl>
        <dt>Gender (M, F, or Other)</dt>
        <dd><input type="text" name="G" value="<?php echo h($player['G']); ?>" /></dd>
      </dl>
      <dl>
        <dt>Birth Year</dt>
        <dd><input type="number" name="birth_year" value="<?php echo h($player['birth_year']); ?>" /></dd>
      </dl>
      <div id="operations">
        <input type="submit" value="Add player" />
      </div>
    </form>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php
require_once('../../private/initialize.php');

require_login();

$player = [
  'FirstName' => $_POST['FirstName'] ?? '',
  'LastName' => $_POST['LastName'] ?? '',
  'G' => $_POST['G'] ?? '',
  'birth_year' => $_POST['birth_year'] ?? '',
];

if(is_post_request()) {

  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  $people = new People($db, (int) $_SESSION['user_id']);

  try {
    $new_id = $people->create([
      'first_name' => $player['FirstName'],
      'last_name' => $player['LastName'],
      'gender' => $player['G'],
      'birth_year' => $player['birth_year'],
    ]);
    if ($is_ajax) {
      $person = $people->find($new_id);
      header('Content-Type: application/json');
      echo json_encode([
        'ok' => true,
        'id' => $person['id'],
        'FirstName' => $person['first_name'],
        'LastName' => $person['last_name'],
        'FullName' => $person['name'],
      ]);
      exit;
    }
    $_SESSION['message'] = 'The player record was created successfully.';
    redirect_to(url_for('/users/show.php?id=' . $new_id));
  } catch (InvalidArgumentException $error) {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(400);
      echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
      exit;
    }
    $errors[] = $error->getMessage();
  }

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

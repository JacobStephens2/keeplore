<?php
require_once('../../private/initialize.php');
require_login();
$person = (new People($db, (int) $_SESSION['user_id']))->find((int) ($_GET['id'] ?? 0));
if ($person === null) {
  $_SESSION['message'] = 'That user was not found.';
  redirect_to(url_for('/users/index.php'));
}
$page_title = 'Show User';
include(SHARED_PATH . '/header.php'); 
?>

<main>

  <div class="object show">

    <h1>
      Name: <?php echo h($person['name']); ?>
    </h1>

    <dl>
      <dt>Gender</dt>
      <dd><?php echo h($person['gender']); ?></dd>
    </dl>
    <dl>
      <dt>Age</dt>
      <dd><?php echo $person['birth_year'] ? (date('Y') - $person['birth_year']) : ''; ?></dd>
    </dl>
    <dl>
      <dt>Birth Year</dt>
      <dd><?php echo h((string) $person['birth_year']); ?></dd>
    </dl>

    <a href="<?php echo url_for('/users/edit.php?id=' . h(u($person['id']))); ?>">
      Edit User
    </a>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

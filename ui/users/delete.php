<?php

require_once('../../private/initialize.php');
require_login();

$people = new People($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);

if(is_post_request()) {
  try {
    $people->delete($id);
    $_SESSION['message'] = 'The player was deleted successfully.';
  } catch (OutOfBoundsException) {
    $_SESSION['message'] = 'That user was not found.';
  }
  redirect_to(url_for('/users/index.php'));
}

$person = $people->find($id);
if ($person === null) {
  $_SESSION['message'] = 'That user was not found.';
  redirect_to(url_for('/users/index.php'));
}

?>

<?php $page_title = 'Delete player'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <a class="back-link" href="<?php echo url_for('/users/index.php'); ?>">&laquo; Back to List</a>

  <div class="object delete">
    <h1>Delete player</h1>
    <p>Are you sure you want to delete this player?</p>
    <p class="item"><?php echo h($person['name']); ?></p>

    <form action="<?php echo url_for('/users/delete.php?id=' . h(u($person['id']))); ?>" method="post">
      <?php echo csrf_input(); ?>
      <div id="operations">
        <input type="submit" name="commit" value="Delete player" />
      </div>
    </form>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

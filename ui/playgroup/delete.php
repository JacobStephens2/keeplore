<?php
require_once('../../private/initialize.php');
require_login();

$playgroup = new Playgroup($db, (int) $_SESSION['user_id']);
$ID = (int) ($_GET['ID'] ?? 0);
$member = $playgroup->member($ID);
if ($member === null) {
  error_404();
}

if(is_post_request()) {
  try {
    $playgroup->remove($ID);
  } catch (OutOfBoundsException) {
    error_404();
  }
  $_SESSION['message'] = 'The player was successfully removed from the playgroup.';
  redirect_to(url_for('/playgroup/index.php'));
}

$page_title = 'Remove User From Group';
include(SHARED_PATH . '/header.php');

?>

<main>

  <div class="object delete">
    <h1><?php echo $page_title; ?></h1>
    <p>Are you sure you want to remove this user from the group?</p>
    <p class="item"><?php echo h($member['name']); ?></p>

    <form action="<?php echo url_for('/playgroup/delete.php?ID=' . h(u($member['id']))); ?>" method="post">
      <?php echo csrf_input(); ?>
      <div ID="operations">
        <input type="submit" name="commit" value="Remove User From Group" />
      </div>
    </form>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

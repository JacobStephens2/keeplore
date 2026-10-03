<?php

require_once('../../private/initialize.php');
require_login();

if(!isset($_GET['id'])) {
  redirect_to(url_for('/artifacts/index.php'));
}
$id = (int) $_GET['id'];
$items = new Items($db, (int) $_SESSION['user_id']);

if(is_post_request()) {

  try {
    $items->delete($id);
  } catch (OutOfBoundsException $not_found) {
    error_404();
  }
  $_SESSION['message'] = 'The item was deleted successfully.';
  redirect_to(url_for('/artifacts/index.php'));

}

$object = $items->find($id);
if (!$object) {
  error_404();
}

?>

<?php $page_title = 'Delete Item'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <a class="back-link" href="<?php echo url_for('/artifacts/index.php'); ?>">&laquo; Items</a>

  <div class="object delete">
    <h1>Delete item</h1>
    <p>Are you sure you want to delete this item?</p>
    <p class="item"><?php echo h($object['Title']); ?></p>

    <form action="<?php echo url_for('/artifacts/delete.php?id=' . h(u($object['id']))); ?>" method="post">
      <?php echo csrf_input(); ?>
      <div id="operations">
        <input type="submit" name="commit" value="Delete Item" />
      </div>
    </form>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

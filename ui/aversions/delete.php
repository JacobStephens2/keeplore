<?php
require_once('../../private/initialize.php');
require_login();

$aversions = new Aversions($db, (int) $_SESSION['user_id']);
$id = (int) ($_GET['id'] ?? 0);
$aversion = $aversions->find($id);
if ($aversion === null) {
  error_404();
}

if(is_post_request()) {
  try {
    $aversions->remove($id);
  } catch (OutOfBoundsException) {
    error_404();
  }
  $_SESSION['message'] = 'The aversion was deleted successfully.';
  redirect_to(url_for('/aversions/index.php'));
}

$page_title = 'Delete Aversion';
include(SHARED_PATH . '/header.php');

?>

<main>

  <div class="response-delete">
    <h1><?php echo $page_title; ?></h1>
    <p>Are you sure you want to delete this aversion?</p>
    <p class="item">Aversion date: <?php echo h($aversion['date']); ?></p>
    <p class="item">Item: <?php echo h($aversion['item']); ?></p>
    <p class="item">Person: <?php echo h($aversion['person']); ?></p>

    <form
      action="<?php echo url_for('/aversions/delete.php?id=' . h(u($id))); ?>"
      method="post"
      >
      <?php echo csrf_input(); ?>
      <div id="operations">
        <input type="submit" name="commit" value="Delete Aversion" />
      </div>
    </form>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

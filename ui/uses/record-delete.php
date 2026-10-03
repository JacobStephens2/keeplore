<?php

  require_once('../../private/initialize.php');
  require_login();

  $uses = new Uses($db, (int) $_SESSION['user_id']);
  $use = $uses->find((int) ($_GET['id'] ?? 0));
  if ($use === null) {
    $_SESSION['message'] = 'That use was not found.';
    redirect_to(url_for('/uses/interactions.php'));
  }

  if(is_post_request()) {
    try {
      $uses->delete($use['id']);
      $_SESSION['message'] = 'The use was deleted successfully.';
    } catch (OutOfBoundsException $error) {
      $_SESSION['message'] = 'That use was not found.';
    }
    redirect_to(url_for('/uses/interactions.php'));
  }

?>

<?php $page_title = 'Delete Interaction'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <div class="response-delete">
    <h1><?php echo $page_title; ?></h1>
    <p>Are you sure you want to delete this use?</p>
    <p class="item">Interaction id: <?php echo h($use['id']); ?></p>
    <p class="item">Interaction date: <?php echo h($use['use_date']); ?></p>
    <p class="item">Item: <?php echo h($use['item_title']); ?></p>
    <p class="item">People: 
    <?php echo h(implode(', ', array_column($use['people'], 'name'))); ?>
    </p>

    <form action="<?php echo url_for('/uses/record-delete.php?id=' . h(u($use['id']))); ?>" method="post">
      <?php echo csrf_input(); ?>
      <div id="operations">
        <input type="submit" name="commit" value="Delete Interaction" />
      </div>
    </form>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

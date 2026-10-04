<?php 
  require_once('../../private/initialize.php');
  require_login_or_guest();
  $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  $object = $id ? (new Items($db, (int) $_SESSION['user_id']))->find($id) : null;
  if ($object === null) {
    error_404();
  }
  $page_title = 'Show Item';
  include(SHARED_PATH . '/header.php');
?>

<main>

  <li><a class="back-link" href="<?php echo url_for('/artifacts/index.php'); ?>">&laquo; Items</a></li>
  <li><a class="back-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">&laquo; Interact By List</a></li>
  <?php if (!is_guest()) { ?>
  <li><a class="back-link" href="<?php echo url_for('/artifacts/new.php'); ?>">&laquo; Create Item</a></li>  <li><a class="back-link" href="<?php echo url_for('/uses/record-new.php?artifact_id=' . h(u($object['id']))); ?>">&laquo; Record Interaction</a></li>
  <?php } ?>
  
  <h1>Title: <?php echo h($object['Title']); ?></h1>

  <?php
    $picture = normalize_item_image_url($object['image_url'] ?? '');
    if ($picture !== '') {
  ?>
  <p class="item-picture-wrap">
    <img class="item-picture" src="<?php echo h($picture); ?>" alt="<?php echo h($object['Title']); ?>" referrerpolicy="no-referrer">
  </p>
  <?php } ?>

  <?php echo item_bgg_link_html($object['bgg_url'] ?? ''); ?>
  <?php echo item_bgg_basis_html($object); ?>
  
  <dl>
    <dt>Acquisition Date</dt>
    <dd><?php echo h($object['Acq']); ?></dd>
  </dl>
  
  <dl>
    <dt>Tracked?</dt>
    <dd><?php echo artifact_is_kept($object) ? 'true' : 'false'; ?></dd>
  </dl>
  
  <dl>
    <dt>Type</dt>
    <dd><?php echo h($object['type']); ?></dd>
  </dl>

  <dl>
    <dt>Tags</dt>
    <dd><?php echo $object['tags'] === [] ? 'None' : h(implode(', ', $object['tags'])); ?></dd>
  </dl>

  <?php if (!is_guest()) { ?>
  <li><a class="back-link" href="<?php echo url_for('/artifacts/edit.php?id=' . h(u($object['id']))); ?>">Edit</a></li>
  <?php } ?>
  
</main>

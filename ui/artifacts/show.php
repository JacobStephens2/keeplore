<?php 
  require_once('../../private/initialize.php');
  require_login_or_guest();
  $id = $_GET['id'] ?? '1';
  $object = find_artifact_by_id($id);
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

  <?php
    $tag_user_id = (int) ($object['user_id'] ?? ($_SESSION['user_id'] ?? 0));
    $item_tags = $tag_user_id > 0
      ? (find_item_tags_for_artifacts($db, [(int) $object['id']], $tag_user_id)[(int) $object['id']] ?? [])
      : [];
  ?>
  <dl>
    <dt>Tags</dt>
    <dd><?php echo $item_tags === [] ? 'None' : h(implode(', ', $item_tags)); ?></dd>
  </dl>

  <?php if (!is_guest()) { ?>
  <li><a class="back-link" href="<?php echo url_for('/artifacts/edit.php?id=' . h(u($object['id']))); ?>">Edit</a></li>
  <?php } ?>
  
</main>

<?php
require_once('../../private/initialize.php');
require_login();
$members = (new Playgroup($db, (int) $_SESSION['user_id']))->members();
$page_title = 'User Group';
include(SHARED_PATH . '/header.php');
?>

<main>
  <div class="objects listing">
    <h1><?php echo $page_title; ?></h1>

    <div class="actions">
      <li><a class="action" href="<?php echo url_for('/playgroup/new.php'); ?>">Add to User Group</a></li>
      <li><a class="action" href="<?php echo url_for('/playgroup/choose.php'); ?>">Choose Games for User Group</a></li>
    </div>

  	<table class="list">
  	  <tr>
        <th>Name (<?php echo count($members); ?>)</th>
        <th>User Group ID&ensp;</th>
        <th></th>
  	  </tr>

      <?php foreach ($members as $member) { ?>
        <tr>
          <td>
            <a 
              class="table-action" 
              href="<?php echo url_for('/users/edit.php?id=' . h(u($member['person_id']))); ?>"
              >
              <?php echo h($member['name']); ?>
            </a>
          </td>
          <td>
            <a class="table-action" href="<?php echo url_for('/playgroup/edit.php?ID=' . h(u($member['id']))); ?>">
              <?php echo h($member['id']); ?>
            </a>
          </td>
          <td>    
            <a class="table-action" href="<?php echo url_for('/playgroup/delete.php?ID=' . h(u($member['id']))); ?>">
              Remove
            </a>
          </td>
    	  </tr>
      <?php } ?>
  	</table>
  </div>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

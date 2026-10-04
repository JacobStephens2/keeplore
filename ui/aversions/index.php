<?php 
require_once('../../private/initialize.php');
require_login();
$aversions = (new Aversions($db, (int) $_SESSION['user_id']))->all();
$page_title = 'Item Aversions';
include(SHARED_PATH . '/header.php');
?>

<main>
    <h1><?php echo $page_title; ?></h1>

  	<table class="list">
  	  <tr id="headerRow">
        <th>Aversion Date (<?php echo count($aversions); ?>)</th>
        <th>Title</th>
        <th>Player</th>
  	  </tr>

      <?php foreach ($aversions as $aversion) { ?>
        <tr>
          <td class="date">
            <a class="action" href="<?php echo url_for('/aversions/edit.php?id=' . h(u($aversion['id']))); ?>">
              <?php echo h($aversion['date']); ?>
            </a>
          </td>

    	    <td>
            <?php echo h($aversion['item']); ?>
          </td>
    	    
          <td class="playerName">
            <?php echo h($aversion['person']); ?>
          </td>
    	  </tr>
      <?php } ?>
  	</table>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

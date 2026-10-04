<?php
  require_once('../../private/initialize.php');
  require_login();
  $page_title = 'Choose for group';
  include(SHARED_PATH . '/header.php');
  if(is_post_request()) {
    $_SESSION['range'] = $_POST['range'] ?? 'false';
    $_SESSION['kept'] = $_POST['kept'] ?? 0;
  }
  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);
  $range = $_SESSION['range'] ?? 'false';
  $kept = $_SESSION['kept'] ?? 0;
  $playgroup = new Playgroup($db, (int) $_SESSION['user_id']);
  $artifacts = $playgroup->choose($type_filter['selected'], $range == 'true', $kept == 1);
  $group_size = count($playgroup->members());
?>

<main>
  <div class="objects listing">
    <h1>Choose Items for Group of <?php echo $group_size; ?> Users</h1>
    <p>
      The dates represent the most recent instance of the type of response indicated by the column header. SS = sweet spot, Mnp = minimum player count, Mxp = maximum player count.
    </p>
    <!-- Parameters form -->
    <form action="<?php echo url_for('/playgroup/choose.php'); ?>" method="post">
      <?php echo csrf_input(); ?>

        <div style="display: flex">
          <label for="range">Show only items matching count of user group</label>
          <input type="hidden" name="range" value="false" />
          <input type="checkbox" id="range" name="range" value="true" <?php if($range == 'true') { echo " checked"; } ?> />
        </div>

        <div style="display: flex">
          <label for="kept">Show only items kept</label>
          <input type="hidden" name="kept" value="0" />
          <input type="checkbox" id="kept" name="kept" value="1" <?php if($kept == 1) { echo " checked"; } ?> />
        </div>

        <label for="type">Item type</label>
        <section id="type">
          <?php require_once '../../private/shared/artifact_type_checkboxes.php'; ?>
        </section>

        <input type="submit" value="Submit" />
    </form>

    <p><?php echo count($artifacts); ?> results</p>

  	<table class="list">
  	  <tr class="header-row">
        <th class="table-header">Item</th>
  	    <th class="table-header">User</th>
        <th class="table-header">SS</th>
        <th class="table-header">MnP</th>
        <th class="table-header">MxP</th>
        <th class="table-header">MxT</th>
  	    <th class="table-header">Use</th>
  	    <th class="table-header">Aversion</th>
  	    <th class="table-header">Type</th>
  	  </tr>

      <?php foreach ($artifacts as $artifact) { ?>
        <tr>
          <td class="edit">
            <a class="table-action" href="<?php echo url_for('/artifacts/edit.php?id=' . h(u($artifact['id']))); ?>">
              <?php echo h($artifact['title']); ?>
            </a>
          </td>
    	    <td class="edit name">
            <a class="table-action" href="<?php echo url_for('/users/edit.php?id=' . h(u($artifact['PlayerID']))); ?>">
              <?php echo h($artifact['FirstName']) . ' ' . h($artifact['LastName']); ?>
            </a>
          </td>
    	    <td class="edit"><?php echo ltrim(h($artifact['ss']), '0'); ?></td>
          <td class="edit"><?php echo h($artifact['MnP']); ?></td>
          <td class="edit"><?php echo h($artifact['MxP']); ?></td>
          <td class="edit"><?php echo h($artifact['MxT']); ?></td>
          <td class="edit date">
            <?php echo h($artifact['MaxOfPlayDate']); ?>
          </td>
          <td class="edit">
            <a class="table-action" href="<?php echo url_for('/aversions/edit.php?id=' . h(u($artifact['ResponseID']))); ?>">
              <?php echo h($artifact['MaxOfAversionDate']); ?></td>
            </a>
          <td class="edit"><?php echo h($artifact['type']); ?></td>
    	  </tr>
      <?php } ?>
  	</table>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

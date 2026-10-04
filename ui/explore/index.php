<?php 

require_once('../../private/initialize.php');

require_login_or_guest();

$kept = $_POST['kept'] ?? '';
$favCt = $_POST['favCt'] ?? '';
$type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);
$items = characteristic_items($db, (int) $_SESSION['user_id'], $type_filter['selected'], [
  'kept' => $kept == 'true',
  'order' => $favCt == 'true' ? 'fav_count' : null,
]);

$page_title = 'Explore Items';

include(SHARED_PATH . '/header.php');

?>

<main>
  <div class="objects listing">
    <header class="page-header page-header-row">
      <div>
        <p class="section-label">Explore</p>
        <h1>Items by characteristic</h1>
        <p class="page-lede">Filter the collection by type and attributes.</p>
      </div>
      <div class="page-header-actions">
        <a class="secondary-link" href="<?php echo url_for('/artifacts/new.php'); ?>">Create item</a>
        <a class="prominent-link" href="<?php echo url_for('/artifacts/useby.php'); ?>">Interact by date</a>
      </div>
    </header>

    <form class="filter-panel" action="<?php echo url_for('/explore/index.php'); ?>" method="post">
      <?php echo csrf_input(); ?>
      <dl>
        <dt>Item Type</dt>
        <dd id="type">
          <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
        </dd>
        <dt>Show only kept artifacts
          <input type="hidden" name="kept" value="" />
          <input type="checkbox" name="kept" value="true"<?php if($kept == 'true') { echo " checked"; } ?> />
        </dt>
        <dt>Order by Fav Ct
          <input type="hidden" name="favCt" value="" />
          <input type="checkbox" name="favCt" value="true"<?php if($favCt == 'true') { echo " checked"; } ?> />
          (default order = sweet spot > max time > min time > age > fav ct > bgg rating)
        </dt>
      </dl>
      <div id="operations">
        <input type="submit" value="Submit" />
      </div>
    </form>

    <div class="table-scroll">
  	<table class="list">
      <thead>
  	  <tr>
        <th>Name</th>
        <th>Kept</th>
        <th>Type</th>
        <th>Min players</th>
        <th>Max players</th>
        <th>Sweet spot</th>
        <th>Year</th>
        <th>Weight</th>
        <th>Fav Ct</th>
        <th>Age</th>
        <th>BGG Rat</th>
  	  </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $object) { ?>
        <tr>
          <td><?php echo h($object['Title']); ?></td>
          <td><?php echo artifact_is_kept($object) ? 'true' : 'false'; ?></td>
    	    <td><?php echo h($object['type_name']); ?></td>
    	    <td><?php echo h($object['MnP']); ?></td>
    	    <td><?php echo h($object['MxP']); ?></td>
    	    <td><?php echo h($object['SS']); ?></td>
    	    <td><?php echo h($object['Yr']); ?></td>
    	    <td><?php echo h($object['Wt']); ?></td>
    	    <td><?php echo h($object['FavCt']); ?></td>
    	    <td><?php echo h($object['Age']); ?></td>
    	    <td><?php echo h($object['BGG_Rat']); ?></td>
    	  </tr>
      <?php } ?>
      </tbody>
  	</table>
    </div>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
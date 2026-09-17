<?php 
  require_once('../../private/initialize.php');
  require_login_or_guest();

  $page_title = 'Types';
  
  include(SHARED_PATH . '/header.php'); 
  include(SHARED_PATH . '/dataTable.html');
?>

<style>
  .tooltip:hover {
    background: black;
  }
</style>

<main>
  <div class="objects listing">
    <header class="page-header page-header-row">
      <div>
        <p class="section-label">Catalog</p>
        <h1>Types</h1>
        <p class="page-lede">Artifact categories and how many you keep in each.</p>
      </div>
      <div class="page-header-actions">
        <?php if (!is_guest()) { ?>
        <a class="prominent-link" href="/types/new">New type</a>
        <?php } ?>
      </div>
    </header>

    <div class="table-scroll">
  	<table class="list" data-page-length='100'>

      <thead>
        <tr id="headerRow">
          <th>Type</th>
          <th>Items Kept</th>
          <th>Items Not Kept</th>
        </tr>
      </thead>

      <tbody>
        <?php 
          $user_id = $_SESSION['user_id'];
          $types = query("SELECT id, ObjectType FROM types WHERE user_id = '$user_id'");

          foreach($types as $type) { 
            
            $type_name = $type['ObjectType'];
            $type_id = $type['id'];

            $query = 
              "SELECT COUNT(id) AS artifacts_kept_of_this_type
              FROM games
              WHERE type_id = '$type_id'
              AND user_id = '$user_id'
              AND is_kept = '1'
            ";
            $artifacts_kept_of_this_type = singleValueQuery($query);

            $artifacts_unkept_of_this_type = singleValueQuery(
              "SELECT COUNT(id) AS artifacts_kept_of_this_type
              FROM games
              WHERE type_id = '$type_id'
              AND user_id = '$user_id'
              AND is_kept = '0'
            ");
            ?>
            
            <tr>
              <td>
                <?php if (!is_guest()) { ?>
                <a href="/types/edit?id=<?php echo $type['id']; ?>">
                  <?php echo h($type_name); ?>
                </a>
                <?php } else { echo h($type_name); } ?>
              </td>
              <td>
                <a href="/artifacts/?type=<?php echo $type['id']; ?>&kept=yes">
                  <?php echo h($artifacts_kept_of_this_type); ?>
                </a>
              </td>
              <td>
                <a href="/artifacts/?type=<?php echo $type['id']; ?>&kept=no">
                  <?php echo h($artifacts_unkept_of_this_type); ?>
                </a>
              </td>
            </tr>
            
            <?php 
          } 
        ?>
      </tbody>
  	</table>
    </div>

    <script>
      let table = new DataTable('table', {
        // options
        order: [
          [ 1, 'desc'], // count kept
          [ 2, 'desc'], // count unkept
          [ 0, 'asc'] // name
        ], 
      });
    </script>
  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

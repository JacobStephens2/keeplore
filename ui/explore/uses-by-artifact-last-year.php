<?php 

require_once('../../private/initialize.php');

require_login_or_guest();

$page_title = 'Uses By Item Over Last 365 Days';

include(SHARED_PATH . '/header.php');
include(SHARED_PATH . '/dataTable.html'); 

$since = (new DateTimeImmutable(record_use_today()))->modify('-365 days')->format('Y-m-d');
$use_counts = (new Uses($db, (int) $_SESSION['user_id']))->useCounts($since);

// find the last letter of the name
// and set fitting punctuation
if (substr($_SESSION['FullName'], -1, 1) == 's') {
  $possessivePunctuation = "' ";
} else {
  $possessivePunctuation = "'s ";
}

?>


<script defer src="uses-by-artifact-last-year.js"></script>

<main>
  
  <h1>
    <?php echo h($_SESSION['FullName'] . $possessivePunctuation . $page_title); ?>
  </h1>

  <a href="uses-by-artifact.php">
    <p>All Uses By Item</p>
  </a>

  <table id='usesByArtifact'>

    <thead>
      <tr>
        <th>Uses</th>
        <th>Item</th>
        <th>Type</th>
      </tr>
    </thead>
    
    <tbody>
      <?php foreach ($use_counts as $use_count) { ?>
        <tr>
          <td>
            <?php echo $use_count['use_count']; ?>
          </td>
          <td>
            <a href="/artifacts/edit.php?id=<?php echo $use_count['item_id']; ?>">
              <?php echo h($use_count['item_title']); ?>
            </a>
          </td>
          <td>
            <?php echo h($use_count['item_type'] ?? ''); ?>
          </td>
        </tr>
      <?php } ?>
    </tbody>

  </table>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
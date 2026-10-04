<?php 

  require_once('../../private/initialize.php');

  require_login_or_guest();

  $page_title = 'Uses By Item';

  include(SHARED_PATH . '/header.php');

  if ((new People($db, (int) $_SESSION['user_id']))->me() === null) {
    echo 'Go to users, choose yourself, ensure "This user is me" is checked and submit the form to count your legacy plays.';
  }

  $use_counts = (new Uses($db, (int) $_SESSION['user_id']))->useCounts();

  // find the last letter of the name
  // and set fitting punctuation
  if (substr($_SESSION['username'], -1, 1) == 's') {
    $possessivePunctuation = "' ";
  } else {
    $possessivePunctuation = "'s ";
  }

?>

<main>
  
  <h1>
    <?php echo h($_SESSION['username'] . $possessivePunctuation . $page_title); ?>
  </h1>

  <a href="uses-by-artifact-last-year.php">
    <p>Uses over last 365 days</p>
  </a>

  <table>

    <tr>
      <th>Uses</th>
      <th>Item</th>
    </tr>

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
      </tr>
    <?php } ?>

  </table>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
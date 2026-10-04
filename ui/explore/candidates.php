<?php // initialize page

  $page_title = 'Candidates';

  require_once('../../private/initialize.php');

  require_login_or_guest();

  $type_filter = type_filter($db, (int) $_SESSION['user_id'], $_SERVER['REQUEST_METHOD'], $_POST, $_SESSION);
  $resultObject = candidate_items($db, (int) $_SESSION['user_id'], $type_filter['selected'], [
    'online' => ['showOnlyOnline' => 'only', 'hideOnline' => 'hide'][$_POST['showOnline'] ?? ''] ?? null,
    'exclude_names' => [(string) ($_POST['removeUserByName'] ?? ''), (string) ($_POST['removeUserByNameTwo'] ?? '')],
  ]);

  include(SHARED_PATH . '/header.php');
  include(SHARED_PATH . '/dataTable.html'); 

?>

<script defer src="candidates.js"></script>
<link rel="stylesheet" href="candidates.css">

<main>

  <h1>
    <?php echo $resultObject->num_rows . " " . $page_title; if ($_SERVER['REQUEST_METHOD'] == 'POST') { echo ' Match Search Results'; } ?>
  </h1>

  <form action="candidates.php" method="POST">
    <?php echo csrf_input(); ?>

    <section style="display: flex; gap: 1.2rem">
      <div>      
        <label for="showAll" style="display: inline">Show All</label>
        <input type="radio" name="showOnline" id="showAll" value="showAll"
          <?php if (isset($_POST['showOnline']) && $_POST['showOnline'] == 'showAll') { echo ' checked '; } ?>
        >
      </div>

      <div>      
        <label for="hideOnline" style="display: inline">Hide Online</label>
        <input type="radio" name="showOnline" id="hideOnline" value="hideOnline"
          <?php if (isset($_POST['showOnline']) && $_POST['showOnline'] == 'hideOnline') { echo ' checked '; } ?>
        >
      </div>
      
      <div>
        <label for="showOnlyOnline" style="display: inline">Show Only Online</label>
        <input type="radio" name="showOnline" id="showOnlyOnline" value="showOnlyOnline"
        <?php if (isset($_POST['showOnline']) && $_POST['showOnline'] == 'showOnlyOnline') { echo ' checked '; } ?>
        >
      </div>
    </section>

    <label for="artifactType">Item type</label>
    <section id="artifactType" style="display: flex; flex-wrap: wrap">
      <?php require_once SHARED_PATH . '/artifact_type_checkboxes.php'; ?>
    </section>
    
    <section style="display: flex; gap: 1.3rem">
      <div style="margin-top: 0.6rem">
        <label for="removeUserByName" style="display: inline">Remove User By Name</label>
        <input type="text" name="removeUserByName" id="removeUserByName" style="display: inline"
          value="<?php if (isset($_POST['removeUserByName'])) { echo h($_POST['removeUserByName']); } ?>"
        >
      </div>
      
      <div style="margin-top: 0.6rem">
        <label for="removeUserByNameTwo" style="display: inline">Remove Second User By Name</label>
        <input type="text" name="removeUserByNameTwo" id="removeUserByNameTwo" style="display: inline"
          value="<?php if (isset($_POST['removeUserByNameTwo'])) { echo h($_POST['removeUserByNameTwo']); } ?>"
        >
      </div>
    </section>

    <input type="submit" value="Submit">
  </form>

  <table id="candidates" data-page-length='100' class="list">

    <thead style="position: sticky; top: 0;">
      <tr>
        <th>Type</th>
        <th>Users</th>
        <th>Group and Setting</th>
        <th>Group Date</th>
        <th>Item (<?php echo $resultObject->num_rows; ?>)</th>
        <th>Recent Use</th>
        <th>SwS</th>
        <th>MnP</th>
        <th>MxP</th>
        <th>AvgT</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($resultObject as $row) { 
        // find most recent use
          $artifact_id = (int) $row['id'];
          $current_user_id = (int) $_SESSION['user_id'];

          $stmt_uses = mysqli_prepare($db, "SELECT MAX(use_date) AS most_recent_use_date FROM uses WHERE artifact_id = ? AND user_id = ?");
          mysqli_stmt_bind_param($stmt_uses, "ii", $artifact_id, $current_user_id);
          mysqli_stmt_execute($stmt_uses);
          $mostRecentUseDateArray = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_uses));
          mysqli_stmt_close($stmt_uses);

          $mostRecentUseDate = $mostRecentUseDateArray['most_recent_use_date'] ?? null;
          $interval_time_ago = strtotime('now - ' . DEFAULT_USE_INTERVAL . ' days');
        //
        ?>
        <tr>
          <td class="type">
            <?php echo h($row['type']); ?>
          </td>

          <td class="users">
            <?php echo substr(h($row['Candidate']),0,3); ?>
          </td>
          
          <td class="group_and_setting">
            <?php echo substr(h($row['Candidate']),3); ?>
          </td>
          
          <td class="date group_date"
            <?php 
              $candidate_group_date = substr(h($row['CandidateGroupDate']), 0, 10);
              
              $candidate_group_date_time = strtotime($candidate_group_date);

              if ($candidate_group_date_time < $interval_time_ago) {
                  echo ' style="color: red;" ';
              }
              ?>
              >
              <?php

              echo $candidate_group_date; 
            ?>
          </td>
          
          <td class="artifact">
            <a href="/artifacts/edit.php?id=<?php echo $row['id']; ?>">
              <?php echo $row['Title']; ?>
            </a>
          </td>

          <td class="mostRecentUse date">
            <?php if ($mostRecentUseDate) {
              $mostRecentUseTime = strtotime($mostRecentUseDate);
              $color = $mostRecentUseTime < $interval_time_ago ? ' style="color: red;"' : '';
              ?>
              <a href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($mostRecentUseDateArray['id']))); ?>"<?php echo $color; ?>>
                <?php echo h(substr($mostRecentUseDate, 0, 10)); ?>
              </a>
            <?php } ?>
          </td>

          <td class="sweet_spot">
            <?php echo h($row['SS']); ?>
          </td>
          <td class="minimum_players">
            <?php echo h($row['MnP']); ?>
          </td>
          <td class="maximum_players">
            <?php echo h($row['MxP']); ?>
          </td>
          
          <td class="average_time">
            <?php 
              echo h(($row['MnT'] + $row['MxT']) / 2); 
            ?>
          </td>
        </tr>
      <?php } ?>
    </tbody>

  </table>

  <label for="typeOptions">Type Options</label>
  <select id="typeOptions">
    <?php 
      $type = '';
      include(SHARED_PATH . '/artifact_type_options.php'); 
    ?>
  </select>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
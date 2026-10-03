<?php // Initialize file

  $page_title = 'Record Use';

  require_once('../../private/initialize.php');
  require_login();

  $formProcessingFile = 'record-new.php';

  if(is_post_request()) {

    /* Sample post request body

      $_POST: Array 
      (
        [useDate] => 2023-01-12
        [artifact] => Array
          (
              [name] => Age of Empires IV
              [id] => 2807
          )

        [user] => Array
          (
            [0] => Array
                (
                    [name] => Jacob Stephens
                    [id] => 141
                )

            [1] => Array
                (
                    [name] => Luke Boerman
                    [id] => 91
                )

          )
      )
    */

    $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $after_record = record_use_return_path($_POST, url_for('/uses/' . $formProcessingFile));

    if ($_POST['artifact']['name'] == '') {

      if ($is_ajax) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Please choose an item.']);
        exit;
      }

      $_SESSION['message'] = "Please choose an item.";

      redirect_to($after_record);

    } else {

      // insert_use exits on a DB error, so an unfinished batch is rolled
      // back when the connection closes instead of half-saving.
      mysqli_begin_transaction($db);
      record_uses($_POST, 'insert_use');
      $new_id = mysqli_insert_id($db);
      $insertResult = mysqli_commit($db);

      if($insertResult === true) {
        $message = record_use_success_message($_POST);

        if ($is_ajax) {
          $status = compute_artifact_use_by_status((int) $_POST['artifact']['id'], (int) $_SESSION['user_id']);
          $artifact_row = find_artifact_by_id((int) $_POST['artifact']['id']);
          header('Content-Type: application/json');
          echo json_encode(record_use_ajax_payload($_POST, (int) $new_id, $status, $artifact_row));
          exit;
        }

        // Only this page's own form offers its group again; quick records
        // from the dashboard, Use By, and Edit User post here by AJAX.
        if (($_POST['return_to'] ?? '') !== 'user-edit') {
          $_SESSION['record_use_group'] = record_use_group($_POST, record_use_today());
        }
        $_SESSION['message'] = $message;
        redirect_to($after_record);
      } else {
        if ($is_ajax) {
          header('Content-Type: application/json');
          http_response_code(500);
          echo json_encode(['ok' => false, 'message' => 'Failed to record interaction.']);
          exit;
        }
        $errors = $insertResult;
      }
    }


  }

  if (isset($_GET['artifact_id'])) {
    $artifact_id = $_GET['artifact_id'];
    $artifact_name = singleValueQuery(
      "SELECT Title FROM games WHERE id = '$artifact_id' "
    );
  } else {
    $artifact_id = null;
    $artifact_name = null;
  }

  $form = record_use_form($_SESSION['record_use_group'] ?? null, isset($_GET['again']), [
    'people' => [['id' => (int) $_SESSION['player_id'], 'name' => (string) $_SESSION['FullName']]],
    'useDate' => record_use_today(),
    'Note' => most_recent_use_setting((int) $_SESSION['user_id']),
  ]);

  include(SHARED_PATH . '/header.php'); 
?>

<script type="module" src="modules/searchArtifactsList.js"></script>
<script type="module" src="modules/getUsers.js"></script>
<script type="module" src="modules/addNewUser.js"></script>
<script type="module" src="modules/addNewEntity.js"></script>
<script defer src="record-new.js?v=3"></script>

<main>

  <h1>
    <?php echo $page_title; ?>
  </h1>

  <form action="<?php echo $formProcessingFile; ?>" method="post" class="record-use-page">
    <?php echo csrf_input(); ?>

    <div class="record-use-top">
      <input type="submit" value="Submit">

      <?php if ($form['offerGroup'] !== null) { ?>
        <a class="secondary-link record-again" href="<?php echo $formProcessingFile; ?>?again=1">
          Record another use with this group
        </a>
        <span class="record-again-names"><?php echo h(implode(', ', array_column($form['offerGroup']['people'], 'name'))); ?></span>
      <?php } ?>
    </div>

    <div class="field-head">
      <label for="SearchTitles">Search Items</label>
      <button type="button" id="showNewEntity" class="new-interactor-toggle">
        + New item
      </button>
    </div>
    <input type="search" 
      id="SearchTitles" 
      name="artifact[name]" 
      value="<?php echo $artifact_name; ?>"
      data-userid="<?php echo $_SESSION['user_id']; ?>"
    >
    <input type="hidden" id="SearchTitleSubmission" name="artifact[id]" 
      value="<?php echo $artifact_id; ?>"
    >
    <div class="searchResults" style="display: none;">
      <ul class="searchResults" style="margin-top: 0;">
        <li></li>
      </ul>
    </div>

    <div id="newEntityForm" class="new-interactor-form" style="display: none;">
      <input type="text" id="newEntityTitle" placeholder="Item name" autocomplete="off">
      <button type="button" id="createEntity" class="new-interactor-create">Create &amp; select</button>
      <button type="button" id="cancelNewEntity" class="new-interactor-cancel">Cancel</button>
      <span id="newEntityMsg" class="new-interactor-msg" role="status" aria-live="polite"></span>
    </div>

    <div class="field-head">
      <label for="user0name">People</label>
      <button type="button" id="showNewInteractor" class="new-interactor-toggle">
        + New person
      </button>
    </div>
    <section id="users">
      <?php foreach ($form['people'] as $i => $person) { ?>
        <div class="<?php echo $i === 0 ? 'person-row' : 'sweetSpot'; ?>" id="SwSDiv<?php echo $i; ?>">
          <input
            type="search"
            class="user"
            id="user<?php echo $i; ?>name"
            name="user[<?php echo $i; ?>][name]"
            value="<?php echo h($person['name']); ?>"
            data-userid="<?php echo $_SESSION['user_id']; ?>"
            data-listposition="<?php echo $i; ?>"
            autocomplete="off"
          >
          <!-- Right after the search so Tab reaches results before the buttons. -->
          <div id="userResultsDiv<?php echo $i; ?>" class="userResults" style="display: none;">
            <ul id="userResults<?php echo $i; ?>" class="userResults"></ul>
          </div>
          <input
            type="hidden"
            id="user<?php echo $i; ?>id"
            name="user[<?php echo $i; ?>][id]"
            value="<?php echo (int) $person['id']; ?>"
          >
          <?php if ($i === 0) { ?>
            <button id="addUser" class="user" type="button" aria-label="Add another person">+</button>
          <?php } else { ?>
            <button class="user remove-user" type="button" aria-label="Remove this person">-</button>
          <?php } ?>
        </div>
      <?php } ?>
    </section>

    <div id="newInteractorForm" class="new-interactor-form" style="display: none;">
      <input type="text" id="newInteractorFirst" placeholder="First name" autocomplete="off">
      <input type="text" id="newInteractorLast" placeholder="Last name" autocomplete="off">
      <button type="button" id="createInteractor" class="new-interactor-create">Create &amp; add</button>
      <button type="button" id="cancelNewInteractor" class="new-interactor-cancel">Cancel</button>
      <span id="newInteractorMsg" class="new-interactor-msg" role="status" aria-live="polite"></span>
    </div>

    <div class="field-pair">
      <div>
        <label for="date">Date</label>
        <input type="date" name="useDate" id="date" 
          value="<?php echo h($form['useDate']); ?>"
        >
      </div>
      <div>
        <label for="useCount">Number of uses</label>
        <input type="number" name="useCount" id="useCount"
          value="1" min="1" max="<?php echo RECORD_USE_MAX_COUNT; ?>" step="1"
        >
      </div>
    </div>

    <label for="Note">Setting</label>
    <input type="text" 
      name="Note" 
      id="Note"
      value="<?php echo h($form['Note']); ?>"
    >

    <label for="NotesTwo">Notes</label>
    <textarea 
      cols="30" 
      rows="3"
      name="NotesTwo" 
      id="NotesTwo"
    ></textarea>

    <input type="submit" value="Submit">

  </form>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
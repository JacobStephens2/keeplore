<?php
  require_once('../../private/initialize.php');
  require_login();
  if(!isset($_GET['id'])) {
    redirect_to(url_for('/artifacts/index.php'));
  }
  $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($id === false) {
    error_404();
  }

  $artifact = find_artifact_by_id($id);
  if (!$artifact || (int) $artifact['user_id'] !== (int) $_SESSION['user_id']) {
    error_404();
  }

  $user_id = $_SESSION['user_id'];
  $stmt = mysqli_prepare($db, "SELECT default_use_interval FROM users WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $user_id);
  mysqli_stmt_execute($stmt);
  $default_interval_result = mysqli_stmt_get_result($stmt);
  $default_interval_row = mysqli_fetch_array($default_interval_result);
  $default_interval = ($default_interval_row !== null) ? $default_interval_row[0] : null;
  mysqli_stmt_close($stmt);

  if(is_post_request()) {
    // The edit form has no format inputs: carry the row's current format
    // flags forward so saving edits never clears them.
    $prior_format = [
      'is_digital' => $artifact['is_digital'] ?? null,
      'is_physical' => $artifact['is_physical'] ?? null,
    ];
    // Handle form values sent by new.php
    $artifact = [];
    $artifact['is_digital'] = $prior_format['is_digital'];
    $artifact['is_physical'] = $prior_format['is_physical'];
    $artifact['id'] = $id ?? '';
    $artifact['Title'] = $_POST['Title'] ?? '';
    $artifact['is_in_secondary_collection'] = $_POST['is_in_secondary_collection'] ?? 0;
    $artifact['Acq'] = $_POST['Acq'] ?? date('Y-m-d');
    $artifact['age'] = $_POST['age'] ?? 0;
    if ($artifact['age'] == '') {
      $artifact['age'] = 0;
    }
    $artifact['Yr'] = trim((string) ($_POST['Yr'] ?? ''));
    $artifact['bgg_url'] = $_POST['bgg_url'] ?? '';
    $artifact['bgg_player_votes'] = $_POST['bgg_player_votes'] ?? '';
    $artifact['bgg_age_basis'] = $_POST['bgg_age_basis'] ?? '';

    if ($artifact['Acq'] == '') {
      $artifact['Acq'] = date('Y-m-d');
    }
    $artifact['type'] = $_POST['type'] ?? '';

    $artifact['interaction_frequency_days'] = $_POST['interaction_frequency_days'] ?? $default_interval;
    $artifact['is_kept'] = $_POST['is_kept'] ?? '';
    $artifact['to_get_rid_of'] = $_POST['to_get_rid_of'] ?? '0';
    $artifact['Candidate'] = $_POST['Candidate'] ?? '';
    $artifact['CandidateGroupDate'] = date('Y-m-d');
    $artifact['UsedRecUserCt'] = '0';
    $artifact['Notes'] = $_POST['Notes'] ?? '';
    ($_POST['MnT'] == '') ? $artifact['MnT'] = 5 : $artifact['MnT'] = $_POST['MnT'];
    ($_POST['MxT'] == '') ? $artifact['MxT'] = 240 : $artifact['MxT'] = $_POST['MxT'];
    ($_POST['MnP'] == '') ? $artifact['MnP'] = 5 : $artifact['MnP'] = $_POST['MnP'];
    ($_POST['MxP'] == '') ? $artifact['MxP'] = 240 : $artifact['MxP'] = $_POST['MxP'];
    ($_POST['SS'] == '') ? $artifact['SS'] = 1 : $artifact['SS'] = $_POST['SS'];
    $result = update_artifact($artifact);
    if($result === true) {
      replace_item_tags($db, $id, (int) $_SESSION['user_id'], $_POST['tags'] ?? '');
      $_SESSION['message'] = 'The item was updated successfully.';
      redirect_to(url_for('/artifacts/edit.php?id=' . $id));
    } else {
      $errors = $result;
    }
  }

  $artifact = find_artifact_by_id($id);
  $item_tags = find_item_tags_for_artifacts($db, [$id], (int) $_SESSION['user_id'])[$id] ?? [];
  if (is_post_request() && isset($_POST['tags'])) {
    $item_tags = parse_item_tags_input($_POST['tags']);
  }

  $sweetSpotsResultObject = find_sweet_spots_by_artifact_id($id);

  $page_title = h($artifact['Title']); 
  include(SHARED_PATH . '/header.php'); 
?>

<link rel="stylesheet" href="<?php echo url_for('/proposals/proposals.css'); ?>">
<main>

  <div id="editArtifact" class="object edit">
    <h1>Edit <?php echo h($artifact['Title']); ?></h1>

    <?php
      $picture = normalize_item_image_url($artifact['image_url'] ?? '');
      if ($picture !== '') {
    ?>
    <p class="item-picture-wrap">
      <img class="item-picture" src="<?php echo h($picture); ?>" alt="<?php echo h($artifact['Title']); ?>" referrerpolicy="no-referrer">
    </p>
    <?php } ?>

    <?php echo item_bgg_link_html($artifact['bgg_url'] ?? ''); ?>

    <?php echo display_errors($errors); ?>

    <div class="edit-actions">
      <a class="back-link"
        href="<?php echo url_for('/uses/record-new.php?artifact_id=' . h(u($id))); ?>"
        >
        Record Use
      </a>

      <a class="back-link" href="<?php echo url_for('/proposals/edit.php?item_id=' . h(u($id))); ?>">
        Record proposal outcome
      </a>

      <button id="editFormDisplayButton" type="button">
        Toggle Edit Form Display
      </button>
    </div>

    <form id="editForm" class="form-layout" data-shortcut="save"
      action="<?php echo url_for('/artifacts/edit?id=' . h(u($id))); ?>"
      method="post"
      >
      <?php echo csrf_input(); ?>

      <div class="form-field-span">
        <button type="submit">Save Edits <kbd>s</kbd></button>
      </div>

      <div class="form-field form-field-span">
        <label for="Title">Title</label>
        <input type="text" name="Title" id="Title" value="<?php echo h($artifact['Title']); ?>" />
      </div>

      <div class="form-field form-field-check">
        <input type="hidden" name="is_kept" value="0" />
        <input type="checkbox" name="is_kept" id="is_kept" value="1"<?php if(artifact_is_kept($artifact)) { echo " checked"; } ?> />
        <label for="is_kept">Kept? (Checked means yes)</label>
      </div>

      <div class="form-field form-field-check">
        <input type="hidden" name="to_get_rid_of" value="0" />
        <input type="checkbox" name="to_get_rid_of" id="to_get_rid_of" value="1"<?php if($artifact['to_get_rid_of'] == "1") { echo " checked"; } ?> />
        <label for="to_get_rid_of">To Get Rid Of? (Checked means yes)</label>
      </div>

      <div class="form-field">
        <?php
          $type_id = $artifact['type_id'];
          require SHARED_PATH . '/artifact_type_search.php';
        ?>
      </div>

      <div class="form-field">
        <label for="tags">Tags (comma-separated)</label>
        <input type="text" name="tags" id="tags"
          value="<?php echo h(implode(', ', $item_tags)); ?>"
          placeholder="portable, beach-safe, two-player, party"
        />
      </div>

      <div class="form-field">
        <label for="Acq">Tracking Start Date</label>
        <input type="date" name="Acq" id="Acq" value="<?php echo h($artifact['Acq']); ?>" />
      </div>

      <div class="form-field">
        <label for="interaction_frequency_days">Interaction Frequency (Days)</label>
        <input type="number" step="0.1" name="interaction_frequency_days" id="interaction_frequency_days"
          onwheel="this.blur()"
          value="<?php
            if ($artifact['interaction_frequency_days'] === null) {
              echo $default_interval;
            } else {
              echo h($artifact['interaction_frequency_days']);
            }
            ?>"
        >
      </div>

      <div class="form-field">
        <label for="SS">Sweet Spot(s)</label>
        <input type="text" name="SS" id="SS" aria-describedby="SS-bgg-basis" value="<?php echo $artifact['SS']; ?>">
        <?php echo item_bgg_field_basis_html($artifact, 'sweet_spot', 'SS'); ?>
      </div>

      <div class="form-field">
        <label for="age">Minimum Age</label>
        <input type="number" name="age" id="age" aria-describedby="age-bgg-basis" value="<?php echo $artifact['Age']; ?>">
        <?php echo item_bgg_field_basis_html($artifact, 'age', 'age'); ?>
      </div>

      <?php
      if (SWEET_SPOT_BUTTONS_ON == true) {
        ?>
        <div class="form-field-span">
          <section id="sweetSpots">
            <?php
            $i = 0;
            foreach ($sweetSpotsResultObject as $row) {
              ?>
              <div>
                <input
                  class="sweetSpot"
                  type="number"
                  name="SwS[<?php echo $i; ?>]"
                  id="SS<?php echo $row['id']; ?>"
                  value="<?php echo $row['SwS']; ?>"
                >
                <button class="sweetSpot">-</button>
              </div>
              <?php
              $i++;
            }
            ?>
          </section>
          <button
            id="addSweetSpot"
            class="sweetSpot"
            style="display: block;"
            >
            +
          </button>
        </div>
        <?php
      }
      ?>

      <script defer src="edit.js?v=2"></script>

      <div class="form-field">
        <label for="MnP">Minimum User Count</label>
        <input type="number" name="MnP" id="MnP" aria-describedby="MnP-bgg-basis" value="<?php echo $artifact['MnP']; ?>">
        <?php echo item_bgg_field_basis_html($artifact, 'players', 'MnP'); ?>
      </div>

      <div class="form-field">
        <label for="MxP">Maximum User Count</label>
        <input type="number" name="MxP" id="MxP" aria-describedby="MxP-bgg-basis" value="<?php echo $artifact['MxP']; ?>">
        <?php echo item_bgg_field_basis_html($artifact, 'players', 'MxP'); ?>
      </div>

      <div class="form-field">
        <label for="MnT">Minimum Time</label>
        <input type="number" name="MnT" id="MnT" value="<?php echo $artifact['MnT']; ?>">
      </div>

      <div class="form-field">
        <label for="MxT">Maxiumum Time</label>
        <input type="number" name="MxT" id="MxT" value="<?php echo $artifact['MxT']; ?>">
      </div>

      <div class="form-field">
        <label for="Yr">Year</label>
        <input type="number" name="Yr" id="Yr" min="1" max="9999" step="1"
          value="<?php echo h($artifact['Yr'] ?? ''); ?>"
        >
      </div>

      <div class="form-field form-field-span">
        <?php $bgg_keep_title = true; include(SHARED_PATH . '/bgg_lookup_panel.php'); ?>
        <input type="hidden" name="bgg_player_votes" id="bgg_player_votes" value="<?php echo h((string) ($artifact['bgg_player_votes'] ?? '')); ?>">
        <input type="hidden" name="bgg_age_basis" id="bgg_age_basis" value="<?php echo h((string) ($artifact['bgg_age_basis'] ?? '')); ?>">
        <label for="bgg_url">BoardGameGeek Link</label>
        <input type="url" name="bgg_url" id="bgg_url" maxlength="1024"
          placeholder="https://boardgamegeek.com/boardgame/..."
          value="<?php echo h(normalize_item_bgg_url($artifact['bgg_url'] ?? '')); ?>"
        >
      </div>

      <div class="form-field form-field-check form-field-span">
        <input type="checkbox" name="is_in_secondary_collection" id="is_in_secondary_collection" value="1"
          <?php if(artifact_is_in_secondary_collection($artifact)) { echo " checked"; } ?>
        />
        <label for="is_in_secondary_collection">Kept in Secondary Collection? (Checked means yes)</label>
      </div>

      <?php
      if (!isset($artifact['Notes'])) {
        $artifact['Notes'] = '';
      }
      ?>

      <div class="form-field form-field-span">
        <label for="Notes">Notes</label>
        <textarea
          name="Notes"
          id="Notes"
          cols="30"
          rows="10"
          ><?php echo h($artifact['Notes']); ?></textarea>
      </div>

      <div class="form-field-span">
        <button type="submit">Save Edits <kbd>s</kbd></button>
      </div>
    </form>

  </div>

  <section id="interactionsList">
    <?php
      $usesOfArtifactByUserResultObject = find_uses_by_artifact_id($artifact['id']);
    ?>
    <h2>
      You have recorded
      <?php echo $usesOfArtifactByUserResultObject->num_rows; ?>
      interactions with
      <?php echo h($artifact['Title']); ?>
    </h2>
    <table>
      <tr>
        <th>Interaction Date</th>
        <th>People</th>
      </tr>
      <?php foreach ($usesOfArtifactByUserResultObject as $useRow) { ?>
        <tr>
          <td>
            <a href="<?php echo url_for('/uses/record-edit.php?id=' . h(u($useRow['id']))); ?>">
              <?php echo h(substr($useRow['use_date'] ?? '', 0, 10)); ?>
            </a>
          </td>
          <td><?php echo h($useRow['players'] ?? ''); ?></td>
        </tr>
      <?php } ?>
    </table>
  </section>

  <?php include(SHARED_PATH . '/proposal_history.php'); ?>

  <p id="deleteArtifact">
    <a class="action" href="<?php echo url_for('/artifacts/delete.php?id=' . h(u($_REQUEST['id']))); ?>">
      Delete 
      <?php echo h($artifact['Title']); ?>
    </a>
  </p>

</main>

<script>
  let editForm = document.querySelector('#editForm');

  function toggleEditFormDisplay() {
    editForm.hidden = !editForm.hidden;
  }

  let editFormDisplayButton = document.querySelector('#editFormDisplayButton');

  editFormDisplayButton.addEventListener('click', toggleEditFormDisplay);
</script>
<script src="<?php echo url_for('/shared/js/form-save-shortcut.js'); ?>?v=1"></script>
<script src="<?php echo url_for('/artifacts/new-bgg.js'); ?>?v=9"></script>

<?php include(SHARED_PATH . '/footer.php'); ?>

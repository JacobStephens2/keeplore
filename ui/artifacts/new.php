<?php
require_once('../../private/initialize.php');
require_login();

$defaultMnT = 30;
$defaultMxT = 60;
$defaultMnP = 1;
$defaultMxP = 1;
$defaultSS = '01';

$user_id = $_SESSION['user_id'];
$default_interval = singleValueQuery(
  "SELECT default_use_interval
  FROM users
  WHERE id = '$user_id'
");

if(is_post_request()) {

  $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

  $artifact = [];
  $artifact['Title'] = $_POST['Title'] ?? '';
  $artifact['Acq'] = $_POST['Acq'] ?? date('Y-m-d');
  $artifact['type'] = $_POST['type'] ?? '';
  if ($artifact['type'] === '') {
    $artifact['type'] = DEFAULT_TYPE;
  }
  // A quick add (e.g. from Record Use) defaults to kept; the full form posts is_kept explicitly.
  $artifact['is_kept'] = $_POST['is_kept'] ?? ($is_ajax ? '1' : '');
  $artifact['Candidate'] = $_POST['Candidate'] ?? '';
  $artifact['interaction_frequency_days'] = $_POST['interaction_frequency_days'] ?? $default_interval;
  $artifact['CandidateGroupDate'] = date('Y-m-d');
  $artifact['UsedRecUserCt'] = 0;
  $artifact['Notes'] = $_POST['Notes'] ?? '';
  (($_POST['MnT'] ?? '') == '') ? $artifact['MnT'] = $defaultMnT : $artifact['MnT'] = $_POST['MnT'];
  (($_POST['MxT'] ?? '') == '') ? $artifact['MxT'] = $defaultMxT : $artifact['MxT'] = $_POST['MxT'];
  (($_POST['MnP'] ?? '') == '') ? $artifact['MnP'] = $defaultMnP : $artifact['MnP'] = $_POST['MnP'];
  (($_POST['MxP'] ?? '') == '') ? $artifact['MxP'] = $defaultMxP : $artifact['MxP'] = $_POST['MxP'];
  (($_POST['SS'] ?? '') == '') ? $artifact['SS'] = $defaultSS : $artifact['SS'] = $_POST['SS'];
  $artifact['age'] = $_POST['age'] ?? 0;
  if ($artifact['age'] === '') {
    $artifact['age'] = 0;
  }
  $artifact['Yr'] = trim((string) ($_POST['Yr'] ?? ''));
  $artifact['image_url'] = normalize_item_image_url($_POST['image_url'] ?? '');

  $artifact['tags'] = $_POST['tags'] ?? '';
  $result = insert_artifact($artifact);

  if($result === true) {
    $new_id = mysqli_insert_id($db);
    replace_item_tags($db, $new_id, (int) $_SESSION['user_id'], $artifact['tags']);

    if ($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode([
        'ok' => true,
        'id' => $new_id,
        'Title' => $artifact['Title'],
      ]);
      exit;
    }

    $_SESSION['message'] = 'The item was created successfully.';
    redirect_to(url_for('/artifacts/show.php?id=' . $new_id));
  } else {
    if ($is_ajax) {
      header('Content-Type: application/json');
      http_response_code(422);
      echo json_encode(['ok' => false, 'message' => implode(' ', $result)]);
      exit;
    }
    $errors = $result;
  }

} else {
  // display the blank form
  $artifact = [];
  $artifact["Title"] = '';
  $artifact["type"] = '';
  $artifact["Acq"] = '';
  $artifact["is_kept"] = '';
  $artifact["Candidate"] = '';
  $artifact["UsedRecUserCt"] = '';
  $artifact["MnT"] = $defaultMnT;
  $artifact["MxT"] = $defaultMxT;
  $artifact["MnP"] = $defaultMnP;
  $artifact["MxP"] = $defaultMxP;
  $artifact["SS"] = $defaultSS;
  $artifact["age"] = '';
  $artifact["Yr"] = '';
  $artifact['image_url'] = '';
  $artifact['tags'] = '';
}

$page_title = 'Create Item';include(SHARED_PATH . '/header.php');

?>

<main>

  <div class="object new">
    <h1>Create Item</h1>
    <?php echo display_errors($errors); ?>

    <form class="form-layout" data-shortcut="save" action="<?php echo url_for('/artifacts/new'); ?>" method="POST">
      <?php echo csrf_input(); ?>

      <div class="form-field-span create-item-submit">
        <button type="submit">Create Item <kbd>s</kbd></button>
      </div>

      <div class="form-field form-field-span">
        <label for="Title">Name</label>
        <input type="text" name="Title" id="Title" autofocus value="<?php echo h($artifact['Title']); ?>" />
      </div>

      <div class="form-field form-field-span">
        <div class="bgg-lookup">
          <button type="button" id="requestBggData">Request BGG Data</button>
          <p class="bgg-lookup-status" id="bggLookupStatus" hidden></p>
          <div class="bgg-confirm" id="bggConfirm" hidden>
            <img id="bggMatchImage" class="bgg-match-image" alt="" hidden referrerpolicy="no-referrer">
            <p>
              <strong id="bggMatchName"></strong>
              <span id="bggMatchYearWrap">(<span id="bggMatchYear"></span>)</span>
              <span id="bggMatchSource" class="bgg-source" hidden></span>
            </p>
            <p>
              <a id="bggMatchLink" href="#" target="_blank" rel="noopener">View on BoardGameGeek</a>
            </p>
            <div class="bgg-confirm-actions">
              <button type="button" id="bggUseMatch">Use this game</button>
            </div>
            <ul class="bgg-other-matches" id="bggOtherMatches" hidden></ul>
          </div>
        </div>
        <?php $preview_url = normalize_item_image_url($artifact['image_url'] ?? ''); ?>
        <input type="hidden" name="image_url" id="image_url" value="<?php echo h($preview_url); ?>">
        <img id="itemPicturePreview" class="item-picture-preview"
          alt="<?php echo $preview_url !== '' ? h($artifact['Title']) . ' cover' : ''; ?>"
          <?php if ($preview_url !== '') { ?>src="<?php echo h($preview_url); ?>"<?php } else { ?>hidden<?php } ?>
          referrerpolicy="no-referrer">
      </div>

      <div class="form-field">
        <?php
          $type_id = ($artifact['type'] !== '' && $artifact['type'] !== null)
            ? $artifact['type']
            : DEFAULT_TYPE;
          require SHARED_PATH . '/artifact_type_search.php';
        ?>
      </div>

      <div class="form-field">
        <label for="Acq">Tracking Start Date</label>
        <input type="date" name="Acq" id="Acq" value="<?php
          $tz = 'America/New_York';
          $timestamp = time();
          $dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
          $dt->setTimestamp($timestamp); //adjust the object to correct timestamp
          echo $dt->format('Y') . '-' . $dt->format('m') . '-' . $dt->format('d');
        ?>"/>
      </div>

      <div class="form-field">
        <label for="interaction_frequency_days">Interaction Frequency (Days)</label>
        <input type="number" step="0.1" name="interaction_frequency_days" id="interaction_frequency_days"
          value="<?php echo $default_interval; ?>"
          onwheel="this.blur()"
        >
      </div>

      <div class="form-field">
        <label for="SS">Sweet Spot(s)</label>
        <input type="text" name="SS" id="SS"
          value="<?php echo $artifact['SS']; ?>"
          aria-describedby="ss-hint"
        >
        <p id="ss-hint" class="form-field-hint">Ideal player counts, comma-separated. Example: 2, 3, 4</p>
      </div>

      <div class="form-field form-field-check">
        <input type="hidden" name="is_kept" value="0" />
        <input type="checkbox" name="is_kept" id="is_kept" value="1" checked/>
        <label for="is_kept">Kept? (Checked Means Yes)</label>
      </div>

      <div class="form-field">
        <label for="MnP">Minimum User Count</label>
        <input type="number" name="MnP" id="MnP"
          value="<?php echo $artifact['MnP']; ?>"
        >
      </div>

      <div class="form-field">
        <label for="MxP">Maximum User Count</label>
        <input type="number" name="MxP" id="MxP" value="<?php echo $artifact['MxP']; ?>">
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
        <label for="age">Minimum Age</label>
        <input type="number" name="age" id="age" value="<?php echo h($artifact['age']); ?>">
      </div>

      <div class="form-field">
        <label for="Yr">Year</label>
        <input type="number" name="Yr" id="Yr" min="1" max="9999" step="1"
          value="<?php echo h($artifact['Yr']); ?>"
        >
      </div>

      <div class="form-field form-field-span">
        <label for="Notes">Notes</label>
        <textarea name="Notes" id="Notes" cols="30" rows="5"></textarea>
      </div>

      <div class="form-field form-field-span">
        <label for="tags">Tags (comma-separated)</label>
        <input type="text" name="tags" id="tags"
          value="<?php echo h($artifact['tags'] ?? ''); ?>"
          placeholder="portable, beach-safe, two-player, party"
        />
      </div>

      <div id="operations" class="form-field-span create-item-submit">
        <button type="submit">Create Item <kbd>s</kbd></button>
      </div>
    </form>

  </div>

</main>

<script src="<?php echo url_for('/artifacts/new-bgg.js'); ?>?v=5"></script>
<script src="<?php echo url_for('/shared/js/form-save-shortcut.js'); ?>?v=1"></script>

<?php include(SHARED_PATH . '/footer.php'); ?>

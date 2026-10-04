<?php 

require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/bgg_import_jobs.php');
require_once(PRIVATE_PATH . '/item_types.php');
global $db;

require_login();

$page_title = 'Edit User Settings';
$user_id = (int) $_SESSION['user_id'];
$preferences = new Preferences($db, $user_id);
$bgg_ratings = new BggRatings($db, $user_id);

if(is_post_request()) {
  $stmt = mysqli_prepare($db, "UPDATE users SET first_name = ?, last_name = ?, email = ?, username = ? WHERE id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, "ssssi",
    $_POST['first_name'],
    $_POST['last_name'],
    $_POST['email'],
    $_POST['username'],
    $user_id
  );
  $update_result = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

  // An unchecked box posts nothing; it means off.
  $preferences->save([
    'default_use_interval' => $_POST['default_use_interval'] ?? null,
    'default_snooze_days' => $_POST['default_snooze_days'] ?? null,
    'default_setting' => $_POST['default_setting'] ?? null,
    'daily_email' => isset($_POST['daily_email']),
    'daily_email_hour' => $_POST['daily_email_hour'] ?? null,
    'native_notify_enabled' => isset($_POST['native_notify_enabled']),
    'native_notify_hour' => $_POST['native_notify_hour'] ?? null,
    'native_notify_lead_days' => $_POST['native_notify_lead_days'] ?? null,
    'native_notify_past_due' => isset($_POST['native_notify_past_due']),
  ]);

  // Checked against BGG apart from the rest, so a typo or a BGG outage costs
  // only this field.
  try {
    $bgg_message = $bgg_ratings->setOwnReviewer((string) ($_POST['bgg_username'] ?? ''));
  } catch (InvalidArgumentException | BggUnreachable $e) {
    $bgg_error = $e->getMessage();
  }
  $bgg_default_type_result = user_bgg_default_type_set($db, $user_id, $_POST['bgg_default_type_id'] ?? '');
}

$stmt = mysqli_prepare($db, "SELECT first_name, last_name, email, username FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$userResult = mysqli_stmt_get_result($stmt);
$userArray = mysqli_fetch_assoc($userResult) + $preferences->get();
mysqli_stmt_close($stmt);
$bgg_default_type = user_bgg_default_type($db, $user_id);
$types = (new Types($db, $user_id))->all();

?>

<?php include(SHARED_PATH . '/header.php'); ?>

<main>
  <header class="page-header">
    <p class="section-label">Account</p>
    <h1><?php echo h($page_title); ?></h1>
    <p class="page-lede">Profile, intervals, email, and notification preferences.</p>
  </header>

  <?php
      if (isset($update_result) && $update_result === false) {
        echo '<p class="errors">Update failed, please contact support</p>';
      } elseif (isset($update_result) && $update_result === true) {
        echo '<p id="message">Update successful</p>';
      }
      if (isset($bgg_error)) {
        echo '<p class="errors">' . h($bgg_error) . ' Your BoardGameGeek reviewer did not change.</p>';
      } elseif (isset($bgg_message)) {
        echo '<p id="bgg_message">' . h($bgg_message) . '</p>';
      }
      if (isset($bgg_default_type_result) && !$bgg_default_type_result['ok']) {
        echo '<p class="errors">' . h($bgg_default_type_result['error']) . ' Your type for BoardGameGeek items did not change.</p>';
      } elseif (isset($bgg_default_type_result) && $bgg_default_type_result['message'] !== null) {
        echo '<p id="bgg_default_type_message">' . h($bgg_default_type_result['message']) . '</p>';
      }
  ?>

  <form class="surface-panel form-layout" method='POST'>
    <?php echo csrf_input(); ?>
    <div class="form-field">
      <label for="first_name">First Name</label>
      <input
        type="text"
        name="first_name"
        id="first_name"
        value="<?php echo h($userArray['first_name']); ?>"
        required minlength="2" maxlength="255"
      >
    </div>

    <div class="form-field">
      <label for="last_name">Last Name</label>
      <input
        type="text"
        name="last_name"
        id="last_name"
        value="<?php echo h($userArray['last_name']); ?>"
        required minlength="2" maxlength="255"
      >
    </div>

    <div class="form-field">
      <label for="email">Email</label>
      <input
        type="email"
        name="email"
        id="email"
        value="<?php echo h($userArray['email']); ?>"
        required maxlength="255"
      >
    </div>

    <div class="form-field">
      <label for="username">Username</label>
      <input
        type="text"
        name="username"
        id="username"
        value="<?php echo h($userArray['username']); ?>"
        required minlength="8" maxlength="255"
      >
    </div>

    <div class="form-field">
      <label for="default_use_interval">Default Use Interval</label>
      <input
        type="number"
        step="0.1"
        name="default_use_interval"
        id="default_use_interval"
        value="<?php echo h($userArray['default_use_interval']); ?>"
        required min="1"
      >
    </div>

    <div class="form-field">
      <label for="default_snooze_days">Snooze length (days)</label>
      <input
        type="number"
        step="1"
        name="default_snooze_days"
        id="default_snooze_days"
        value="<?php echo h($userArray['default_snooze_days']); ?>"
        required min="1" max="365"
      >
    </div>

    <div class="form-field">
      <label for="default_setting">Default Setting</label>
      <input
        type="text"
        name="default_setting"
        id="default_setting"
        value="<?php echo h($userArray['default_setting']); ?>"
      >
    </div>

    <div class="form-field form-field-check">
      <label for="daily_email">
        <input
          type="checkbox"
          name="daily_email"
          id="daily_email"
          value="1"
          <?php if ($userArray['daily_email']) echo 'checked'; ?>
        >
        Receive daily use-by email
      </label>
    </div>

    <div class="form-field">
      <label for="daily_email_hour">Preferred email time (Eastern Time)</label>
      <select name="daily_email_hour" id="daily_email_hour">
        <?php
          for ($h = 0; $h <= 23; $h++) {
            $label = ($h === 0) ? '12:00 AM (midnight)' :
                     (($h < 12) ? $h . ':00 AM' :
                     (($h === 12) ? '12:00 PM (noon)' :
                     ($h - 12) . ':00 PM'));
            $selected = ((int)$userArray['daily_email_hour'] === $h) ? 'selected' : '';
            echo "<option value=\"$h\" $selected>$label</option>";
          }
        ?>
      </select>
    </div>

    <h2 class="form-field-span">BoardGameGeek</h2>
    <p class="form-field-span" id="bgg_username_help">The BoardGameGeek user whose ratings and comments you follow on your items: your own account or someone else's. Items and Search BGG show a column for them, and Edit Item can request or enter their rating. Leave blank for none.</p>

    <div class="form-field">
      <label for="bgg_username">BoardGameGeek reviewer</label>
      <input
        type="text"
        name="bgg_username"
        id="bgg_username"
        value="<?php echo h(isset($bgg_error) ? (string) ($_POST['bgg_username'] ?? '') : (string) $bgg_ratings->ownReviewer()); ?>"
        maxlength="64"
        autocomplete="off"
        aria-describedby="bgg_username_help"
      >
    </div>

    <div class="form-field">
      <label for="bgg_default_type_id">Type for BoardGameGeek items</label>
      <select name="bgg_default_type_id" id="bgg_default_type_id" aria-describedby="bgg_default_type_help">
        <option value="">Keep Create Item's type</option>
        <?php foreach ($types as $type) { ?>
          <option value="<?php echo $type['id']; ?>"<?php if ($bgg_default_type !== null && $bgg_default_type['id'] === $type['id']) echo ' selected'; ?>><?php echo h($type['name']); ?></option>
        <?php } ?>
      </select>
    </div>
    <p class="form-field-span" id="bgg_default_type_help">When you use a BoardGameGeek match on Create Item, the item gets this type unless you already picked one, such as table game.</p>

    <h2 class="form-field-span">App notifications</h2>
    <p class="form-field-span">These settings control notifications from the Keeplore Android app.</p>

    <div class="form-field form-field-check">
      <label for="native_notify_enabled">
        <input
          type="checkbox"
          name="native_notify_enabled"
          id="native_notify_enabled"
          value="1"
          <?php if ($userArray['native_notify_enabled']) echo 'checked'; ?>
        >
        Enable app notifications
      </label>
    </div>

    <div class="form-field">
      <label for="native_notify_hour">Notification time (your device's local time)</label>
      <select name="native_notify_hour" id="native_notify_hour">
        <?php
          for ($h = 0; $h <= 23; $h++) {
            $label = ($h === 0) ? '12:00 AM (midnight)' :
                     (($h < 12) ? $h . ':00 AM' :
                     (($h === 12) ? '12:00 PM (noon)' :
                     ($h - 12) . ':00 PM'));
            $selected = ((int)$userArray['native_notify_hour'] === $h) ? 'selected' : '';
            echo "<option value=\"$h\" $selected>$label</option>";
          }
        ?>
      </select>
    </div>

    <div class="form-field">
      <label for="native_notify_lead_days">Days before due to notify me (0 to skip the early heads-up)</label>
      <input
        type="number"
        name="native_notify_lead_days"
        id="native_notify_lead_days"
        value="<?php echo h($userArray['native_notify_lead_days']); ?>"
        min="0" max="14" step="1"
      >
    </div>

    <div class="form-field form-field-check">
      <label for="native_notify_past_due">
        <input
          type="checkbox"
          name="native_notify_past_due"
          id="native_notify_past_due"
          value="1"
          <?php if ($userArray['native_notify_past_due']) echo 'checked'; ?>
        >
        Remind me about overdue items
      </label>
    </div>

    <div class="form-field-span">
      <input type="submit" value="Update Settings">
    </div>
  </form>

  <?php $bgg_import = bgg_import_job_view($db, $user_id); ?>
  <section class="surface-panel" id="bgg_import" aria-labelledby="bgg_import_heading">
    <h2 id="bgg_import_heading">BoardGameGeek import</h2>
    <p>Import every rating and comment your reviewer left on items that link to BoardGameGeek. It runs in the background, a few minutes for a large collection, and you can leave this page. Your hand entries stay.</p>
    <p id="bgg_import_status" role="status" aria-live="polite"
      data-status-url="<?php echo url_for('/settings/bgg-import-status.php'); ?>"
      data-active="<?php echo $bgg_import['active'] ? '1' : '0'; ?>"><?php echo h($bgg_import['text']); ?></p>
    <form method="post" action="<?php echo url_for('/settings/bgg-import.php'); ?>">
      <?php echo csrf_input(); ?>
      <?php $bgg_reviewer = $bgg_ratings->ownReviewer(); ?>
      <button type="submit" id="bgg_import_start"<?php if (!$bgg_import['can_queue']) echo ' disabled'; ?>>
        <?php echo $bgg_reviewer === null ? 'Name a reviewer above to import' : 'Import all ' . h($bgg_reviewer) . ' ratings'; ?>
      </button>
    </form>
  </section>
  <script src="<?php echo url_for('/settings/bgg-import.js'); ?>?v=2"></script>

  <a href="<?php echo url_for('/reset-password/index.php'); ?>">
    <p>Reset password</p>
  </a>

  <a href="<?php echo url_for('/settings/agent-keys.php'); ?>">
    <p>Agent API keys</p>
  </a>


</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

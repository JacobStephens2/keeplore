<?php 

require_once('../../private/initialize.php');
global $db;

require_login();

$page_title = 'Edit User Settings';

if(is_post_request()) {
  $daily_email = isset($_POST['daily_email']) ? 1 : 0;
  $daily_email_hour = (int) ($_POST['daily_email_hour'] ?? 8);
  if ($daily_email_hour < 0 || $daily_email_hour > 23) {
    $daily_email_hour = 8;
  }
  $native_notify_enabled = isset($_POST['native_notify_enabled']) ? 1 : 0;
  $native_notify_hour = (int) ($_POST['native_notify_hour'] ?? 9);
  if ($native_notify_hour < 0 || $native_notify_hour > 23) {
    $native_notify_hour = 9;
  }
  $native_notify_lead_days = (int) ($_POST['native_notify_lead_days'] ?? 3);
  if ($native_notify_lead_days < 0 || $native_notify_lead_days > 14) {
    $native_notify_lead_days = 3;
  }
  $native_notify_past_due = isset($_POST['native_notify_past_due']) ? 1 : 0;
  $default_snooze_days = (int) ($_POST['default_snooze_days'] ?? 7);
  if ($default_snooze_days < 1 || $default_snooze_days > 365) {
    $default_snooze_days = 7;
  }
  $user_id = (int) $_SESSION['user_id'];

  $stmt = mysqli_prepare($db, "UPDATE users
    SET first_name = ?, last_name = ?, email = ?, username = ?,
        default_setting = ?, default_use_interval = ?, default_snooze_days = ?, daily_email = ?, daily_email_hour = ?,
        native_notify_enabled = ?, native_notify_hour = ?, native_notify_lead_days = ?, native_notify_past_due = ?
    WHERE id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, "sssssiiiiiiiii",
    $_POST['first_name'],
    $_POST['last_name'],
    $_POST['email'],
    $_POST['username'],
    $_POST['default_setting'],
    $_POST['default_use_interval'],
    $default_snooze_days,
    $daily_email,
    $daily_email_hour,
    $native_notify_enabled,
    $native_notify_hour,
    $native_notify_lead_days,
    $native_notify_past_due,
    $user_id
  );
  $update_result = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
}

$user_id = (int) $_SESSION['user_id'];
$stmt = mysqli_prepare($db, "SELECT
  first_name, last_name, email, username,
  default_use_interval, default_snooze_days, default_setting, daily_email, daily_email_hour,
  native_notify_enabled, native_notify_hour, native_notify_lead_days, native_notify_past_due
  FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$userResult = mysqli_stmt_get_result($stmt);
$userArray = mysqli_fetch_assoc($userResult);
mysqli_stmt_close($stmt);

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

  <a href="<?php echo url_for('/reset-password/index.php'); ?>">
    <p>Reset password</p>
  </a>

  <a href="<?php echo url_for('/settings/agent-keys.php'); ?>">
    <p>Agent API keys</p>
  </a>


</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php
  require_once('../../private/initialize.php');
  $page_title = 'Reset Password';
  include(SHARED_PATH . '/header.php');
?>

<style>
  form {
    padding-left: 3rem;
  }
  div {
    padding-left: 0;
  }
  label {
    font-weight: bold;
    font-size: 1.7rem;
  }
  input {
    display: block;
    margin-top: 0.5rem;
  }
  .update-message {
    padding-left: 3rem;
  }
</style>

<?php
$accounts = new Accounts($db, SmtpMailer::fromEnvironment());
$updating = is_post_request() && ($_POST['action'] ?? '') === 'update';
$email = (string) ($updating ? ($_POST['email'] ?? '') : ($_GET['email'] ?? ''));
$key = (string) ($updating ? ($_POST['key'] ?? '') : ($_GET['key'] ?? ''));
$errors = [];
$reset = false;

if ($updating) {
  try {
    $accounts->resetPassword($email, $key, (string) ($_POST['new_password'] ?? ''), (string) ($_POST['new_password_check'] ?? ''));
    $reset = true;
  } catch (AccountInvalid $invalid) {
    $errors = $invalid->errors;
  }
}

if ($reset) {
  ?>
  <div class="error update-message">
    <p>Your password has been updated successfully.</p>
    <p>
      <a href="https://<?php echo DOMAIN; ?>/login.php">Click here</a> to Login.
    </p>
  </div>
  <?php
} elseif (($updating || ($_GET['action'] ?? '') === 'reset') && $accounts->resetLinkIsValid($email, $key)) {
  echo display_errors($errors);
  ?>
  <br />
  <form method="post" action="reset-password.php" name="update">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="update" />
    <div>
      <label for="new_password">Enter New Password</label>
      <input type="password" name="new_password" id="new_password" minlength="12" required />
    </div>
    <div>
      <label for="new_password_check">Re-Enter New Password</label>
      <input type="password" name="new_password_check" id="new_password_check" minlength="12" required/>
    </div>
    <input type="hidden" name="email" value="<?php echo h($email); ?>"/>
    <input type="hidden" name="key" value="<?php echo h($key); ?>"/>
    <input type="submit" value="Reset Password" />
  </form>
  <?php
} elseif ($updating || isset($_GET['key'])) {
  ?>
  <div class="error">
    <h2>Invalid Link</h2>
    <p>
      The link is invalid or expired. Either you did not copy the correct link from the email,
      the link is more than a day old, or you have already used it.
    </p>
    <p>
      <a href="https://<?php echo DOMAIN; ?>/reset-password/index.php">Click here</a> to reset password.
    </p>
  </div>
  <?php
}

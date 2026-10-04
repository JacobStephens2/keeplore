<?php
require_once('../private/initialize.php');

$errors = [];
$username = '';
$password = '';

if (is_post_request()) {
  require_once(PRIVATE_PATH . '/rate_limiter.php');
  $rate_limiter = new RateLimiter($db);

  if (!$rate_limiter->checkAndRecord('register', 5, 3600)) {
    $errors[] = "Too many registration attempts. Please try again in an hour.";
  }

  $user['first_name'] = $_POST['first_name'] ?? '';
  $user['last_name'] = $_POST['last_name'] ?? '';
  $user['email'] = $_POST['email'] ?? '';
  $user['username'] = $_POST['username'] ?? '';
  $user['password'] = $_POST['password'] ?? '';
  $user['confirm_password'] = $_POST['confirm_password'] ?? '';
  $user['device'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

  if (empty($errors)) {
    try {
      $account = (new Accounts($db, SmtpMailer::fromEnvironment()))->register($user);
      $_SESSION['message'] = 'User registered';
      log_in_user($account);
      redirect_to(url_for('/index.php'));
    } catch (AccountInvalid $invalid) {
      $errors = $invalid->errors;
    }
  }

} else {
  // display the blank form
  $user = [];
  $user["first_name"] = '';
  $user["last_name"] = '';
  $user["email"] = '';
  $user["username"] = '';
  $user['password'] = '';
  $user['confirm_password'] = '';
}
?>

<?php $page_title = 'Register'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>

<main>

  <a class="back-link" href="<?php echo url_for('/login.php'); ?>">&laquo; Back to Login</a>

  <div class="user new">
    <h1>Register as new user</h1>

    <?php echo display_errors($errors); ?>

    <form action="<?php echo url_for('/register.php'); ?>" method="post">
      <?php echo csrf_input(); ?>
      <label for="first_name">First name</label>
      <input type="text" name="first_name" id="first_name" value="<?php echo h($user['first_name']); ?>" required minlength="2" maxlength="255" />

      <label for="last_name">Last name</label>
      <input type="text" name="last_name" id="last_name" value="<?php echo h($user['last_name']); ?>" required minlength="2" maxlength="255" />

      <label for="username">Username</label>
      <input type="text" name="username" id="username" value="<?php echo h($user['username']); ?>" required minlength="8" maxlength="255" />

      <label for="email">Email</label>
      <input type="email" name="email" id="email" value="<?php echo h($user['email']); ?>" required maxlength="255" />

      <label for="password">Password</label>
      <input type="password" name="password" id="password" value="" required minlength="12" />

      <label for="confirm_password">Confirm Password</label>
      <input type="password" name="confirm_password" id="confirm_password" value="" required minlength="12" />
      <p>
        Passwords should be at least 12 characters and include at least one uppercase letter, lowercase letter, number, and symbol.
      </p>
      <br />

      <div id="operations">
        <input type="submit" value="Create user" />
      </div>
    </form>

  </div>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

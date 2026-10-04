<?php
require_once('../private/initialize.php');

$errors = [];
$username = '';
$password = '';

if(is_post_request()) {

  $username = $_POST['username'] ?? '';
  $password = $_POST['password'] ?? '';

  // Validations
  if(is_blank($username)) {
    $errors[] = "Username or email cannot be blank.";
  }
  if(is_blank($password)) {
    $errors[] = "Password cannot be blank.";
  }

  // if there were no errors, try to login.
  if(empty($errors)) {
    // Using one variable ensures that msg is the same
    $login_failure_msg = "Log in was unsuccessful.";

    $account = (new Accounts($db, SmtpMailer::fromEnvironment()))->logIn($username, $password);
    if($account) {
      log_in_user($account);
      redirect_to(url_for('/index.php'));      
    } else {
      // An unknown name and a wrong password fail alike.
      $errors[] = $login_failure_msg;
    }

  }
  
}

?>

<?php $page_title = 'Log in'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>
  
    <main class="auth-main">
      <section class="auth-layout auth-layout-compact">
        <div class="auth-intro">
          <div class="auth-copy">
            <p class="section-label">Account Access</p>
            <h1>Log in</h1>
            <p>
              Sign in to continue with password reset and account recovery tools.
            </p>
          </div>

          <div class="auth-actions">
            <a class="secondary-link" href="<?php echo url_for('/register.php'); ?>">Create an account</a>
          </div>
        </div>

        <div class="auth-panel">
          <?php echo display_errors($errors); ?>

          <form action="login.php" method="post" class="auth-form">
            <?php echo csrf_input(); ?>
            <label for="username">Username or email</label>
            <input class="input-box" type="text" name="username" id="username" value="" required/>

            <label for="password">Password</label>
            <input class="input-box" type="password" name="password" id="password" value="" required/>

            <input class="submit auth-submit" type="submit" name="submit" value="Log in" />
          </form>

          <a class="auth-text-link" href="<?php echo url_for('/reset-password/index.php'); ?>">Reset password</a>
        </div>
      </section>
    </main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php
require_once('../private/initialize.php');
require_once(PRIVATE_PATH . '/rate_limiter.php');

$errors = [];
$username = '';
$password = '';

if(is_post_request()) {

  $rate_limiter = new RateLimiter($db);

  // 5 login attempts per 15 minutes per IP
  if (!$rate_limiter->checkAndRecord('login', 5, 900)) {
    $errors[] = "Too many login attempts. Please try again in 15 minutes.";
  }

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

    $account = accounts()->logIn($username, $password);
    if($account) {
      $logger->logAuth('login_success', ['username' => $username]);
      $remember = isset($_POST['remember_me']);
      log_in_user($account, $remember);
      if (isset($_POST['redirectURL'])) {
        redirect_to(url_for(urldecode($_POST['redirectURL'])));      
      } else {
        redirect_to(url_for('/index.php'));      
      }
    } else {
      // An unknown name and a wrong password fail alike.
      $logger->logAuth('login_failed', ['username' => $username]);
      $errors[] = $login_failure_msg;
    }

  }
  
}

if (isset($_REQUEST['action'])) { 
  $action = $_REQUEST['action'];
} else {
  $action = '';
}
if ($action == 'logout') {
  log_out();
  session_start();
  generate_csrf_token();
}
if ($action == 'guest') {
  start_guest_session();
  redirect_to(url_for('/index.php'));
}

?>

<?php $page_title = 'Log in'; ?>
<?php include(SHARED_PATH . '/header.php'); ?>
  
    <main class="auth-main">
      <section class="auth-layout">
        <div class="auth-intro">
          <div class="auth-hero">
            <img
              src="<?php echo url_for('/assets/keeplore.png'); ?>"
              alt="Keeplore — a quiet shelf of well-loved possessions with interact-by dates"
              width="1400"
              height="788"
              loading="eager"
              decoding="async"
            >
          </div>

          <div class="auth-copy">
            <p class="section-label">Welcome Back</p>
            <h1>Log in</h1>
            <p>
              Keeplore generates interact-by dates so the things you keep stay in use. Inspired by
              <a href="https://www.theminimalists.com/ninety/" target="_blank">The Minimalists' 90/90 Rule</a>
              and built by
              <a href="https://jacobstephens.net" target="_blank">Jacob Stephens</a>.
            </p>
          </div>

          <div class="auth-actions">
            <a class="prominent-link" href="<?php echo url_for('/register.php'); ?>">Create an account</a>
            <a class="secondary-link" href="<?php echo url_for('/login.php?action=guest'); ?>">Browse as guest</a>
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

            <label class="checkbox-row">
              <input type="checkbox" name="remember_me" /> Remember me
            </label>

            <?php
              if (isset($_GET['redirectURL'])) {
                ?>
                <input type="hidden" name="redirectURL" value="<?php echo h(urldecode($_GET['redirectURL'])); ?>">
                <?php
              }
            ?>

            <input class="submit auth-submit" type="submit" name="submit" value="Log in" />
          </form>

          <a class="auth-text-link" href="<?php echo url_for('/reset-password/index.php'); ?>">Reset password</a>
        </div>
      </section>
    </main>

<?php include(SHARED_PATH . '/footer.php'); ?>

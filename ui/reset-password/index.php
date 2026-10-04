<?php
require_once('../../private/initialize.php');

$page_title = 'Reset';
include(SHARED_PATH . '/header.php');
?>

<main>
   <style>
      .update-message {
         padding-left: 0;
      }
   </style>

   <?php

   require_once(PRIVATE_PATH . '/rate_limiter.php');
   $rate_limiter = new RateLimiter($db);

   if(isset($_POST["email"]) && (!empty($_POST["email"]))){

      if (!$rate_limiter->checkAndRecord('password_reset', 3, 900)) {
         echo "<div class='error'><p>Too many reset attempts. Please try again in 15 minutes.</p></div>";
      } else {

      $email = $_POST["email"];
      $email = filter_var($email, FILTER_SANITIZE_EMAIL);
      $email = filter_var($email, FILTER_VALIDATE_EMAIL);

      if (!$email) {
         echo "
            <div class='error'>
               <p>
                  Invalid email address please type a valid email address!
               </p>
            </div>
            <a href='javascript:history.go(-1)'>Go Back</a>";

      } else {

         try {
            accounts()->requestPasswordReset($email);
            echo
               "
               <div class='error update-message'>
                  <p>
                     If an account uses that email address, an email has been
                     sent to it with instructions on how to reset your password.
                  </p>
               </div>
               ";
         } catch (\Throwable $e) {
            echo "<div class='error'><p>Something went wrong. Please try again later.</p></div>";
            error_log('Password reset error: ' . $e->getMessage());
         }
      }
      } // end rate limit else
   } else { ?>

      <form method="post" action="" name="reset">
         <?php echo csrf_input(); ?>
         <label for="email">
            Enter Your Email Address
         </label>
         <input type="email" name="email" id="email" placeholder="name@domain.com" />
         <input type="submit" value="Reset Password"/>
      </form>

   <?php } ?>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>
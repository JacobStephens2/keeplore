<?php

  function find_user_by_username($username) {
    global $db;

    $sql = "SELECT * FROM users WHERE username=? OR email=? LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    $admin = mysqli_fetch_assoc($result); // find first
    mysqli_free_result($result);
    return $admin; // returns an assoc. array
  }
  function validate_user($user, $options=[]) {
    $errors = [];

    $password_required = $options['password_required'] ?? true;

    if(is_blank($user['first_name'])) {
      $errors[] = "First name cannot be blank.";
    } elseif (!has_length($user['first_name'], array('min' => 2, 'max' => 255))) {
      $errors[] = "First name must be between 2 and 255 characters.";
    }

    if(is_blank($user['last_name'])) {
      $errors[] = "Last name cannot be blank.";
    } elseif (!has_length($user['last_name'], array('min' => 2, 'max' => 255))) {
      $errors[] = "Last name must be between 2 and 255 characters.";
    }

    if(is_blank($user['email'])) {
      $errors[] = "Email cannot be blank.";
    } elseif (!has_length($user['email'], array('max' => 255))) {
      $errors[] = "Last name must be less than 255 characters.";
    } elseif (!has_valid_email_format($user['email'])) {
      $errors[] = "Email must be a valid format.";
    }

    if(is_blank($user['username'])) {
      $errors[] = "Username cannot be blank.";
    } elseif (!has_length($user['username'], array('min' => 8, 'max' => 255))) {
      $errors[] = "Username must be between 8 and 255 characters.";
    } elseif (!has_unique_username($user['username'], $user['id'] ?? 0)) {
      $errors[] = "Username not allowed. Try another.";
    }

    if($password_required) {
      if(is_blank($user['password'])) {
        $errors[] = "Password cannot be blank.";
      } elseif (!has_length($user['password'], array('min' => 12))) {
        $errors[] = "Password must contain 12 or more characters";
      } elseif (!preg_match('/[A-Z]/', $user['password'])) {
        $errors[] = "Password must contain at least 1 uppercase letter";
      } elseif (!preg_match('/[a-z]/', $user['password'])) {
        $errors[] = "Password must contain at least 1 lowercase letter";
      } elseif (!preg_match('/[0-9]/', $user['password'])) {
        $errors[] = "Password must contain at least 1 number";
      } elseif (!preg_match('/[^A-Za-z0-9\s]/', $user['password'])) {
        $errors[] = "Password must contain at least 1 symbol";
      }

      if(is_blank($user['confirm_password'])) {
        $errors[] = "Confirm password cannot be blank.";
      } elseif ($user['password'] !== $user['confirm_password']) {
        $errors[] = "Password and confirm password must match.";
      }
    }

    return $errors;
  }
  function insert_user($user) {
    global $db;

    $errors = validate_user($user);
    if (!empty($errors)) {
      return $errors;
    }

    $hashed_password = password_hash($user['password'], PASSWORD_BCRYPT);

    $sql = "INSERT INTO users ";
    $sql .= "(first_name, last_name, email, username, hashed_password, user_group) ";
    $sql .= "VALUES (?, ?, ?, ?, ?, '1')";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "sssss",
      $user['first_name'],
      $user['last_name'],
      $user['email'],
      $user['username'],
      $hashed_password
    );
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // For INSERT statements, $result is true/false
    if($result) {
      notify_admin_new_user($user);
      return true;
    } else {
      // INSERT failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }

  function notify_admin_new_user($user) {
    try {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      $mail->CharSet    = 'UTF-8';
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->Password   = SMTP_PASS;
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = SMTP_PORT;

      $mail->setFrom(SMTP_FROM_EMAIL, APP_NAME);
      $mail->addAddress('jacob@stephens.page');

      $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 1024);
      $mail->Subject = APP_NAME . ' — New Account Created';
      $mail->Body = "A new account was created on " . APP_NAME . ".\n\n"
        . "Name: " . ($user['first_name'] ?? '') . " " . ($user['last_name'] ?? '') . "\n"
        . "Username: " . ($user['username'] ?? '') . "\n"
        . "Email: " . ($user['email'] ?? '') . "\n"
        . "Date: " . gmdate('c') . "\n"
        . "Device: " . $ua;
      $mail->send();
    } catch (\Throwable $e) {
      error_log('Failed to send admin signup notification: ' . $e->getMessage());
    }
  }

?>

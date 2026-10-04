<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/classes/UseByQueue.php';

  function find_sweet_spots_by_artifact_id($artifact_id) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT
      sweetspots.id AS id,
      games.Title AS Title,
      sweetspots.SwS AS SwS
      FROM sweetspots
      JOIN games ON games.id = sweetspots.Title
      WHERE sweetspots.Title = ?
      ORDER BY games.Title ASC
    ");
    mysqli_stmt_bind_param($stmt, "i", $artifact_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result;
  }

function email_artifact_use_notice($user_id) {

  global $db;
  $interval = default_use_interval($db, $user_id);

  $due_today_array = array();
  $overdue_array = array();
  $due_in_coming_week = array();

  // Each section lists items by last use, never used first.
  $entries = (new UseByQueue($db, (int) $user_id))->entries();
  usort($entries, fn ($a, $b) => $a['last_use'] <=> $b['last_use']);
  foreach ($entries as $entry) {
      $notice = [
          'artifact' => h($entry['Title']),
          'artifact_id' => h($entry['id']),
          'use_by_date' => $entry['use_by_date'],
          'most_recent_use' => $entry['last_use'] ?? 'No interactions',
          'interval' => $entry['interaction_frequency_days'] ?? $interval,
      ];
      if ($entry['status'] === 'due_today') {
          $due_today_array[] = $notice;
      } elseif ($entry['status'] === 'upcoming' && $entry['days_until'] <= 7) {
          $due_in_coming_week[] = $notice;
      } elseif ($entry['status'] === 'overdue') {
          $overdue_array[] = $notice;
      }
  }

  $count_to_notify_about =
    count($due_today_array)
    + count($overdue_array)
    + count($due_in_coming_week)
  ;

  if($count_to_notify_about > 0) { // email this list to the user

      // get user email address
      $email_stmt = mysqli_prepare($db, "SELECT email FROM users WHERE id = ?");
      mysqli_stmt_bind_param($email_stmt, "i", $user_id);
      mysqli_stmt_execute($email_stmt);
      $email_result = mysqli_stmt_get_result($email_stmt);
      $email_row = mysqli_fetch_array($email_result);
      mysqli_stmt_close($email_stmt);
      $email = ($email_row !== null) ? $email_row[0] : null;
      if ($email === null) { return 0; }
      // Skip RFC 2606 reserved-TLD addresses (e.g. the seeded demo user
      // demo@artifact.example). They can never receive mail, so sending to
      // them is a guaranteed hard bounce that erodes the domain's Resend
      // sender reputation. Real recipients are unaffected.
      if (preg_match('/\.(example|test|invalid|localhost)$/i', $email)) { return 0; }

      $mail = new PHPMailer(true);

      // Server settings
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Password   = SMTP_PASS;
      $mail->Port       = SMTP_PORT;

      // Recipients
      $mail->setFrom(SMTP_FROM_EMAIL, APP_NAME);
      $mail->addAddress($email);
      $mail->addReplyTo(DEV_EMAIL, DEV_NAME);

      // Content
      $mail->isHTML(true);


      try {
          $mail->Subject = "Interactions Due";
          $body = '';

          $overdue_count = count($overdue_array);
          $due_today_count = count($due_today_array);
          $due_in_coming_week_count = count($due_in_coming_week);

          $body .= '
              <h2 style="margin:0 0 0.5rem;">Summary</h2>
              <p style="margin:0 0 0.25rem;">
                  <strong>' . $count_to_notify_about . '</strong> ' .
                  ($count_to_notify_about === 1 ? 'item needs' : 'items need') .
                  ' attention.
              </p>
              <p style="margin:0 0 0.75rem;">
                  <a href="https://' . DOMAIN . '/artifacts/useby.php">View interact by list</a>
              </p>
              <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:1.25rem;">
                <tr>
                  <td style="font-weight:bold;color:#b63d2f;">Overdue</td>
                  <td style="font-weight:bold;">' . $overdue_count . '</td>
                </tr>
                <tr>
                  <td style="font-weight:bold;">Due today</td>
                  <td style="font-weight:bold;">' . $due_today_count . '</td>
                </tr>
                <tr>
                  <td style="font-weight:bold;">Due in the coming week</td>
                  <td style="font-weight:bold;">' . $due_in_coming_week_count . '</td>
                </tr>
              </table>
              <hr>
          ';

          if ($overdue_count > 0) {
              $body .= '
                  <h1>Interactions overdue</h1>
                  <ul>
              ';

              foreach($overdue_array as $overdue) {
                  $name = $overdue['artifact'];
                  $most_recent_use = $overdue['most_recent_use'];
                  $use_by_date = $overdue['use_by_date'];
                  $id = $overdue['artifact_id'];
                  $interval = $overdue['interval'];
                  $get_rid_of_url = 'https://' . DOMAIN . '/artifacts/mark-get-rid-of.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  if ($most_recent_use === 'No interactions') {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              | <a href='$get_rid_of_url'>Get Rid Of</a>
                              $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  } else {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              | <a href='$get_rid_of_url'>Get Rid Of</a>
                              last interacted $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . " interval: $interval days)
                          </li>
                      ";
                  }
              }

              $body .= '
                  </ul>
              ';
          }

          if (count($due_today_array) > 0) {
              $body .= '
                  <h1>Interactions due today</h1>
                  <ul>
              ';

              foreach($due_today_array as $due_today) {
                  $name = $due_today['artifact'];
                  $most_recent_use = $due_today['most_recent_use'];
                  $id = $due_today['artifact_id'];
                  $interval = $due_today['interval'];
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  $body .= "
                      <li>
                          <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                          <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                          | <a href='$snooze_url'>Snooze</a>
                          last interacted $most_recent_use (interval: $interval days)
                      </li>
                  ";
              }

              $body .= '
                  </ul>
              ';
          }

          if (count($due_in_coming_week) > 0) {
              $body .= '
                  <h1>Interactions due in coming week</h1>
                  <ul>
              ';

              foreach($due_in_coming_week as $artifact) {
                  $name = $artifact['artifact'];
                  $most_recent_use = $artifact['most_recent_use'];
                  $use_by_date = $artifact['use_by_date'];
                  $id = $artifact['artifact_id'];
                  $interval = $artifact['interval'];
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  if ($most_recent_use === 'No interactions') {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  } else {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              last interacted $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  }
              }

              $body .= '
                  </ul>
              ';
          }

          $body .= '
              <p>Record uses at <a href="https://' . DOMAIN . '/uses/record-new.php">' . DOMAIN . '</a></p>
          ';


          $mail->Body = $body;

          $mail_result = $mail->send();

       } catch (Exception $Exception) {

          try {
              $mail->Subject = "Error with Keeplore Uses Due Today Email";
              $mail->Body = '<p>The following Exception was thrown when trying to email an interact by list:</p>
                  <pre>' . print_r($Exception, true) . '</pre>
              ';
              $mail->send();

          } catch (Exception $Exception) {
              file_put_contents(__FILE__ . '.log',
                  'Email exception caught in email notification to dev of error at '
                  . date('Y-m-d H:i:s') . "\n"
                  . print_r($Exception, true) . "\n",
                  FILE_APPEND
              );
          }

       }

  };

  return $count_to_notify_about;
}

?>

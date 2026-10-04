<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/DailyEmail.php';
require_once __DIR__ . '/classes/Mailer.php';

/**
 * GET /send_use_email.php: sends the signed-in owner their Daily email now,
 * through $mailer. The query's userID must be that owner, so the email only
 * ever goes to the requester; the master key, with no owner of its own, is
 * refused the same way. Agent keys are refused (ADR-0002).
 *
 * Returns [status, response fields], with how many items the email told about.
 */
function send_daily_email_over_api(mysqli $db, ApiCaller $caller, array $query, Mailer $mailer): array {
  $refusal = $caller->agentKeyRefusal();
  if ($refusal !== null) {
    return $refusal;
  }

  $owner = $caller->owner();
  $requested_user_id = isset($query['userID']) ? (int) $query['userID'] : null;
  if ($owner === null || $owner !== $requested_user_id) {
    return [403, ['message' => 'You may only send this email to yourself.']];
  }

  return [200, [
    'userID' => $owner,
    'count_to_notify_about' => (new DailyEmail($db, $owner, $mailer))->send(),
  ]];
}

?>

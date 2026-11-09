<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/DailyEmail.php';
require_once __DIR__ . '/classes/Mailer.php';

/**
 * GET /send_use_email.php: sends the signed-in owner their Daily email now,
 * through $mailer. The query's userID must be that owner, so the email only
 * ever goes to the requester; the master key, with no owner of its own, is
 * refused the same way. Agent keys are refused (ADR-0002). $fetchSite is
 * the request's Sec-Fetch-Site header, or null when it sent none. cross-site
 * is refused before the email is sent. same-site, or none, still sends.
 *
 * Returns [status, response fields], with how many items the email told about.
 */
function send_daily_email_over_api(mysqli $db, ApiCaller $caller, array $query, Mailer $mailer, ?string $fetchSite): array {
  // Same cross-site refusal as Browse as guest, Logout, and Exit guest mode.
  $refusal = cross_site_request_refusal($fetchSite, 'send the Daily email');
  if ($refusal !== null) {
    return $refusal;
  }

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

<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/daily_email_api.php');
  require_once('../private/classes/SmtpMailer.php');

  emit_api_response(answer_api_request($database, 'send_use_email', [
    'GET' => fn (ApiCaller $caller, array $request) => send_daily_email_over_api($database, $caller, $request['query'], SmtpMailer::fromEnvironment()),
  ], api_request_from_globals()));

?>

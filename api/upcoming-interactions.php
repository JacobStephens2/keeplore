<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/upcoming_uses_api.php');

  emit_api_response(answer_api_request($database, 'upcoming-interactions', [
    'GET' => fn (ApiCaller $caller) => list_upcoming_uses_over_api($database, $caller),
  ], api_request_from_globals()));

?>

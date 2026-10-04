<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/people_api.php');

  emit_api_response(answer_api_request($database, 'players', [
    'GET' => fn (ApiCaller $caller) => list_people_over_api($database, $caller),
  ], api_request_from_globals()));

?>

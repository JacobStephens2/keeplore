<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/use_api.php');

  emit_api_response(answer_api_request($database, 'uses', [
    'GET' => fn (ApiCaller $caller, array $request) => list_uses_over_api($database, $caller, $request['query']),
    'POST' => fn (ApiCaller $caller, array $request) => record_use_over_api($database, $caller, $request['body']),
    'DELETE' => fn (ApiCaller $caller, array $request) => delete_use_over_api($database, $caller, $request['query']),
  ], api_request_from_globals()));

?>

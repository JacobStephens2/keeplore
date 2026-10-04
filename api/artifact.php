<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/item_api.php');

  emit_api_response(answer_api_request($database, 'artifact', [
    'GET' => fn (ApiCaller $caller, array $request) => read_item_over_api($database, $caller, $request['query']),
    'POST' => fn (ApiCaller $caller, array $request) => write_item_over_api($database, $caller, 'POST', $request['body']),
    'PUT' => fn (ApiCaller $caller, array $request) => write_item_over_api($database, $caller, 'PUT', $request['body']),
    'DELETE' => fn (ApiCaller $caller, array $request) => delete_item_over_api($database, $caller, $request['query']),
  ], api_request_from_globals()));

?>

<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/collection_list_api.php');

  $list_collection = fn (ApiCaller $caller, array $request) => list_collection_over_api($database, $caller, $request['body']);

  emit_api_response(answer_api_request($database, 'artifacts', [
    'POST' => $list_collection,
    'GET' => $list_collection,
  ], api_request_from_globals()));

?>

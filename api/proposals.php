<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/proposals_api.php');

  emit_api_response(answer_api_request($database, 'proposals', [
    'GET' => fn (ApiCaller $caller, array $request) => report_proposals_over_api($database, $caller, $request['query']),
  ], api_request_from_globals()));

?>

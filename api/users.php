<?php

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/people_api.php');

  // Unmetered: Record Use searches as the owner types, which would soon
  // spend the API rate limit and fill the request log.
  emit_api_response(answer_api_request($database, 'users', [
    'POST' => fn (ApiCaller $caller, array $request) => search_people_over_api($database, $caller, $request['body']),
  ], api_request_from_globals(), metered: false));

?>

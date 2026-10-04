<?php

  // Kept toggle for remote agents (spec #10, ticket #18, ADR 0002).
  // POST { "id": 123, "is_kept": 1 } — exactly one kept vocabulary.

  require_once('private/initialize.php');
  require_once('../private/api_request.php');
  require_once('../private/kept_api.php');

  emit_api_response(answer_api_request($database, 'artifact-kept', [
    'POST' => fn (ApiCaller $caller, array $request) => set_kept_over_api($database, $caller, $request['body']),
  ], api_request_from_globals()));

?>

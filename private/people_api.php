<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/People.php';

/**
 * The person search behind POST /users.php, used by Record Use, Edit Use
 * and the Interact By popup: the signed-in owner's people whose name
 * contains the body's query, or all of them for a blank query. Any userid
 * in the body is ignored. Agent keys are refused (ADR-0002), and the
 * master key, which has no people of its own, gets a 400.
 *
 * Returns [status, response fields].
 */
function search_people_over_api(mysqli $db, ApiCaller $caller, $body): array {
  $refusal = $caller->agentKeyRefusal();
  if ($refusal !== null) {
    return $refusal;
  }
  $owner = $caller->owner();
  if ($owner === null) {
    return [400, ['message' => 'users.php requires a user-scoped key.']];
  }
  $query = is_object($body) && isset($body->query) && is_scalar($body->query) ? (string) $body->query : '';

  $people = (new People($db, $owner))->search($query);
  return [200, ['users' => array_map(fn (array $person) => [
    'id' => $person['id'],
    'FullName' => $person['name'],
    'FirstName' => $person['first_name'],
    'LastName' => $person['last_name'],
  ], $people)]];
}

/**
 * GET /players.php: the owner's people, with the ids an agent needs to
 * filter uses by person with GET /uses.php?player_id=. The master key gets
 * a 400.
 *
 * Returns [status, response fields].
 */
function list_people_over_api(mysqli $db, ApiCaller $caller): array {
  $owner = $caller->owner();
  if ($owner === null) {
    return [400, ['message' => 'players.php requires a user-scoped key.']];
  }

  return [200, ['players' => array_map(fn (array $person) => [
    'id' => $person['id'],
    'name' => $person['name'],
    'FirstName' => $person['first_name'],
    'LastName' => $person['last_name'],
    'birth_year' => $person['birth_year'],
    'represents_user_id' => $person['is_me'] ? $owner : null,
  ], (new People($db, $owner))->all())]];
}

?>

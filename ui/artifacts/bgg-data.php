<?php
require_once('../../private/initialize.php');
require_once(PRIVATE_PATH . '/bgg_lookup.php');
require_login();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$object_id = filter_input(INPUT_GET, 'objectid', FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$query = trim((string) ($_GET['query'] ?? ''));

if ($object_id) {
  $result = bgg_fields_for_id($object_id);
} else {
  $result = bgg_lookup_name($query);
}

if (empty($result['ok'])) {
  http_response_code(422);
}

echo json_encode($result);
exit;

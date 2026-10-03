<?php

  require_once('private/initialize.php');
  require_once('../private/people_api.php');
  header('Content-Type: application/json');

  $response = new stdClass;

  $authentication_response = authenticate();
  if ($authentication_response->authenticated != true) {
    echo json_encode($authentication_response);
    exit;
  }
  $response->authentication_response = $authentication_response;

  [$status, $fields] = search_people_over_api(
    $database, $authentication_response, json_decode(file_get_contents('php://input'))
  );
  http_response_code($status);
  foreach ($fields as $field => $value) {
    $response->$field = $value;
  }

  echo json_encode($response);

?>

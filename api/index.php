<?php

  require_once('private/initialize.php');

  header('Content-Type: application/json');

  echo json_encode(agent_api_discovery());

?>
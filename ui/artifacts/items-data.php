<?php
  require_once('../../private/initialize.php');
  require_once('../../private/items_list.php');
  require_login_or_guest();

  header('Content-Type: application/json');
  header('Cache-Control: no-store');

  list($default_use_interval, $typesArray) = items_list_load_filter_defaults($_SESSION['user_id']);

  $filters = items_list_filters_from_request(
    $_GET,
    [],
    'GET',
    $default_use_interval,
    $typesArray
  );
  $items = items_list_payload($db, $filters, $_SESSION['user_id']);

  echo json_encode([
    'items' => $items,
    'count' => count($items),
  ]);

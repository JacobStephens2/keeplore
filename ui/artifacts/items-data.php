<?php
  require_once('../../private/initialize.php');
  require_once('../../private/items_list.php');
  require_login_or_guest();

  header('Content-Type: application/json');
  header('Cache-Control: no-store');

  echo json_encode((new ItemsPage($db, (int) $_SESSION['user_id']))->data($_GET));

<?php

function db_connect() {
  $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
  $connection = new mysqli(
    DB_SERVER,
    DB_USER,
    DB_PASS,
    DB_NAME,
    $port
  );
  confirm_db_connect($connection);
  return $connection;
}

function confirm_db_connect($connection) {
  if($connection->connect_errno) {
    $msg = "Database connection failed: ";
    $msg .= $connection->connect_error;
    $msg .= " (" . $connection->connect_errno . ")";
    exit($msg);
  }
}

function db_disconnect($connection) {
  if(isset($connection)) {
    $connection->close();
  }
}

?>
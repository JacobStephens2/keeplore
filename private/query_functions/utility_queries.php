<?php

function singleValueQuery($query) {
  global $db;
  $result = mysqli_query($db, $query);
  if ($result !== false) {
    $resultArray = mysqli_fetch_array($result);
    if ($resultArray !== null) {
      return $resultArray[0];
    } else {
      return 'No results';
    }
  } else {
    return 'Possible query error';
  }
}

function singleRowQuery($query) {
  global $db;
  $result = mysqli_query($db, $query);
  $resultArray = mysqli_fetch_array($result);
  return $resultArray;
}

function query($query) {
  global $db;
  return mysqli_query($db, $query);
}

?>

<?php

function find_artifacts_by_characteristic($kept, $type, $allArtifacts, $favCt) {
  global $db;

  $sql = "SELECT Title, id, mxt, mnt, ss, yr, wt, mnp, mxp, av, favct, age, bgg_rat, is_kept, type ";
  $sql .= "FROM games ";
  $sql .= "WHERE ";

  $params = [];
  $types = "";

  if ($allArtifacts == 'true') {
    $sql .= "(user_id = ? OR user_id = 8) ";
    $types .= "i";
    $params[] = $_SESSION['user_id'];
  } else {
    $sql .= "user_id = ? ";
    $types .= "i";
    $params[] = $_SESSION['user_id'];
  }

  $sql .= "AND ";

  if ($kept == 'true') {
    $sql .= "is_kept = 1 ";
  } else {
    $sql .= '1 = 1 ';
  }

  $sql .= "AND ";

  if ($type != '1') {
    $sql .= "type = ? ";
    $types .= "s";
    $params[] = $type;
  } else {
    $sql .= "1 = 1 ";
  }

  $sql .= "AND type IS NOT NULL ";
  $sql .= "AND type <> '' ";
  $sql .= "AND ss <> '' ";

  $sql .= "ORDER BY ";
  if ($favCt != '') {
    $sql .= "favct DESC, ss ASC, mxt ASC, mnt ASC, age ASC, bgg_rat DESC ";
  } else {
    $sql .= "ss ASC, mxt ASC, mnt ASC, age ASC, favct DESC, bgg_rat DESC ";
  }

  $stmt = mysqli_prepare($db, $sql);
  if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
  }
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  return $result;
}

?>

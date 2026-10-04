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

/**
 * The owner's items with a Candidate, of the Types in $type_ids (none when
 * it is empty). $options may hold 'online' ('only' or 'hide'; anything
 * else shows all) and 'exclude_names', names whose Candidates are left out.
 */
function candidate_items(mysqli $db, int $user_id, array $type_ids, array $options = []): mysqli_result {
  $sql = "SELECT *
    FROM games
    WHERE Candidate IS NOT NULL
    AND Candidate != '0'
    AND Candidate != ''
    AND user_id = ?
  ";
  $params = [$user_id];
  $types = "i";

  switch ($options['online'] ?? '') {
    case 'only':
      $sql .= " AND Candidate LIKE '%online%' ";
      break;
    case 'hide':
      $sql .= " AND Candidate NOT LIKE '%online%' ";
      break;
  }

  foreach ($options['exclude_names'] ?? [] as $name) {
    if ($name != '') {
      $sql .= " AND Candidate NOT LIKE ? ";
      $params[] = '%' . $name . '%';
      $types .= "s";
    }
  }

  if (count($type_ids) > 0) {
    $sql .= " AND type_id IN (" . implode(',', array_fill(0, count($type_ids), '?')) . ") ";
    foreach ($type_ids as $type_id) {
      $params[] = (int) $type_id;
      $types .= "i";
    }
  } else {
    $sql .= " AND FALSE ";
  }

  $sql .= " ORDER BY type ASC, Candidate ASC, Title ASC";

  $stmt = mysqli_prepare($db, $sql);
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  return mysqli_stmt_get_result($stmt);
}

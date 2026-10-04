<?php

  function find_sweet_spots_by_artifact_id($artifact_id) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT
      sweetspots.id AS id,
      games.Title AS Title,
      sweetspots.SwS AS SwS
      FROM sweetspots
      JOIN games ON games.id = sweetspots.Title
      WHERE sweetspots.Title = ?
      ORDER BY games.Title ASC
    ");
    mysqli_stmt_bind_param($stmt, "i", $artifact_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result;
  }

?>

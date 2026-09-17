<?php

/**
 * Kept-status seam (spec #10, ADR 0001).
 *
 * The single place that defines what "kept" means and how it is written:
 *
 * - Kept predicate: artifact_is_kept() — kept means kept only. Format flags
 *   (digital/physical) never affect membership.
 * - Kept setter: set_artifact_kept() — the only writer the UI, API, and agent
 *   paths use to flip kept status.
 *
 * During the overlap release (tickets #11–#16) reads prefer the new
 * self-documenting columns and fall back to the legacy ones, while writes
 * land in both forms so existing reads keep working untouched. The Contract
 * ticket (#17) removes the legacy columns and the fallback/dual-write paths.
 */

function normalize_kept_value($value) {
  if ($value === true || $value === 1 || $value === '1' || $value === 'yes') {
    return 1;
  }
  return 0;
}

function normalize_secondary_membership($value) {
  if ($value === true || $value === 1 || $value === '1' || $value === 'yes') {
    return 1;
  }
  return 0;
}

function normalize_format_flag($value) {
  if ($value === null || $value === '') {
    return null;
  }
  return normalize_kept_value($value);
}

/**
 * Kept predicate: true when the row is kept in the primary collection.
 * Reads the new is_kept column when present, falling back to legacy KeptCol
 * during the overlap release. Format flags are never consulted.
 */
function artifact_is_kept($row) {
  if (is_object($row)) {
    $row = get_object_vars($row);
  }
  if (!is_array($row)) {
    return false;
  }
  if (array_key_exists('is_kept', $row) && $row['is_kept'] !== null && $row['is_kept'] !== '') {
    return (int) $row['is_kept'] === 1;
  }
  if (array_key_exists('KeptCol', $row) && $row['KeptCol'] !== null && $row['KeptCol'] !== '') {
    return (int) $row['KeptCol'] === 1;
  }
  return false;
}

function artifact_is_in_secondary_collection($row) {
  if (is_object($row)) {
    $row = get_object_vars($row);
  }
  if (!is_array($row)) {
    return false;
  }
  if (array_key_exists('is_in_secondary_collection', $row) && $row['is_in_secondary_collection'] !== null && $row['is_in_secondary_collection'] !== '') {
    return (int) $row['is_in_secondary_collection'] === 1;
  }
  if (array_key_exists('InSecondaryCollection', $row)) {
    return $row['InSecondaryCollection'] === 'yes';
  }
  return false;
}

/**
 * Overlap-release input bridge: when a caller supplies the new
 * self-documenting keys without their legacy counterparts, fill the legacy
 * keys so validation and legacy writes keep working. Legacy keys supplied
 * by the caller always win. Removed by the Contract ticket (#17).
 */
function fill_legacy_kept_keys($artifact) {
  if (!is_array($artifact)) {
    return $artifact;
  }
  if (!array_key_exists('KeptCol', $artifact) && array_key_exists('is_kept', $artifact)) {
    $artifact['KeptCol'] = (string) normalize_kept_value($artifact['is_kept']);
  }
  if (!array_key_exists('InSecondaryCollection', $artifact) && array_key_exists('is_in_secondary_collection', $artifact)) {
    $artifact['InSecondaryCollection'] = normalize_secondary_membership($artifact['is_in_secondary_collection']) === 1 ? 'yes' : null;
  }
  if (!array_key_exists('KeptDig', $artifact) && array_key_exists('is_digital', $artifact)) {
    $artifact['KeptDig'] = normalize_format_flag($artifact['is_digital']);
  }
  if (!array_key_exists('KeptPhys', $artifact) && array_key_exists('is_physical', $artifact)) {
    $artifact['KeptPhys'] = normalize_format_flag($artifact['is_physical']);
  }
  return $artifact;
}

/**
 * Kept setter: flips kept status through the single seam, dual-writing the
 * new is_kept column and the legacy KeptCol column. Scoped to the session
 * user. Returns true on success, false on failure.
 */
function set_artifact_kept($artifact_id, $value) {
  global $db;
  $user_id = (int) $_SESSION['user_id'];
  $artifact_id = (int) $artifact_id;
  $value = normalize_kept_value($value);
  $stmt = mysqli_prepare($db, "UPDATE games SET is_kept = ?, KeptCol = ? WHERE id = ? AND user_id = ? LIMIT 1");
  mysqli_stmt_bind_param($stmt, "iiii", $value, $value, $artifact_id, $user_id);
  $result = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  return $result;
}

?>

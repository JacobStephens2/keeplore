<?php

/**
 * Kept status (spec #10, ADR 0001).
 *
 * The kept predicate and the flag normalizers live here:
 *
 * - Kept predicate: artifact_is_kept() — kept means kept only. Format flags
 *   (digital/physical) never affect membership.
 * - Normalizers: normalize_kept_value() and its secondary-membership and
 *   format-flag forms.
 *
 * The kept writer is Items::setKept(), which the UI and agent paths use to
 * flip kept status.
 *
 * Exactly one kept/format vocabulary: is_kept, is_in_secondary_collection,
 * is_digital, is_physical.
 */

function normalize_kept_value($value) {
  if ($value === true || $value === 1 || $value === '1' || $value === 'yes') {
    return 1;
  }
  return 0;
}

function normalize_secondary_membership($value) {
  return normalize_kept_value($value);
}

function normalize_format_flag($value) {
  if ($value === null || $value === '') {
    return null;
  }
  return normalize_kept_value($value);
}

/**
 * Kept predicate: true when the row is kept in the primary collection.
 * Format flags are never consulted.
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
  return false;
}

/**
 * SQL form of the flag predicates above, for list filters: true matches
 * only 1; false matches 0 and NULL, as artifact_is_kept() reads them.
 * $column must be a trusted column name, never user input.
 */
function artifact_flag_sql($column, $value) {
  return $value ? "{$column} = 1" : "COALESCE({$column}, 0) <> 1";
}

?>

<?php

/**
 * Use-by date ("Interact by" in the UI): the date by which an item should
 * next be used. Every page that shows it gets it from here.
 */

/** Used when the user row has no default use interval. */
const USE_BY_FALLBACK_INTERVAL = 90;

/**
 * The use-by date as Y-m-d, or null when there is no basis for one (no valid
 * acquisition date and no use).
 *
 * The item's own interaction frequency is its interval when set; otherwise
 * $default_interval applies. Never used, or last used before acquisition:
 * acquisition + interval. Otherwise: last use + 2 × interval. Intervals are
 * days and keep their fraction, rounded to the nearest hour.
 *
 * Dates are read and added to in UTC so a daylight-saving change cannot move
 * the result a day. Whether it is overdue is the caller's call.
 */
function use_by_date($acquired, $last_use, $frequency, $default_interval) {
    // Midnight UTC on the date that starts $value, or null if it holds no valid date.
    $parse = static function ($value) {
        $date = substr((string) $value, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    };
    $interval = $frequency !== null && $frequency !== '' ? (float) $frequency : (float) $default_interval;
    $acquired = $parse($acquired);
    $last_use = $parse($last_use);

    if ($last_use !== null && ($acquired === null || $last_use >= $acquired)) {
        $base = $last_use;
        $days = $interval * 2;
    } elseif ($acquired !== null) {
        $base = $acquired;
        $days = $interval;
    } else {
        return null;
    }

    $hours = (int) round($days * 24);
    return $base->modify("+$hours hours")->format('Y-m-d');
}

/** The user's default use interval in days. */
function default_use_interval($db, $user_id) {
    $stmt = mysqli_prepare($db, "SELECT default_use_interval FROM users WHERE id = ?");
    $user_id = (int) $user_id;
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $interval = $row['default_use_interval'] ?? null;
    return $interval !== null ? (float) $interval : (float) USE_BY_FALLBACK_INTERVAL;
}

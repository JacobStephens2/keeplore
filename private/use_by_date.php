<?php

/**
 * Use-by date ("Interact by" in the UI): the date by which an item should
 * next be used. Every page that shows it gets it from here.
 */

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

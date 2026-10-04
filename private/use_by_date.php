<?php

/**
 * Use-by date ("Interact by" in the UI): the date by which an item should
 * next be used, and whether it is overdue, due today or upcoming. Every page
 * that shows it gets it from here.
 */

/**
 * Everything an item's due-ness is shown from, for $item's `Acq`,
 * `last_use`, `interaction_frequency_days` and `snoozed_until` on $today
 * (a Y-m-d day, the app's day in production):
 *
 * - `interval`: the days its use-by date was counted from (float), its own
 *   interaction frequency when set, otherwise $default_interval
 * - `use_by_date`: Y-m-d, or null when there is no basis for one
 * - `days_until`: signed whole days from $today to it, null without one
 * - `status`: `overdue`, `due_today`, `upcoming`, or null without one
 * - `is_snoozed`: its snoozed-until date is after $today, so an item snoozed
 *   until today shows again today. The Use-by queue's "hide snoozed" filter
 *   applies the same rule in SQL.
 */
function use_by_status(array $item, $default_interval, string $today): array {
    $frequency = $item['interaction_frequency_days'] ?? null;
    $interval = $frequency !== null && $frequency !== '' ? (float) $frequency : (float) $default_interval;
    $use_by = use_by_date($item['Acq'] ?? null, $item['last_use'] ?? null, $interval);
    $days_until = $use_by === null ? null : (int) (new DateTimeImmutable($today, new DateTimeZone('UTC')))
        ->diff(new DateTimeImmutable($use_by, new DateTimeZone('UTC')))->format('%r%a');
    $snoozed_until = $item['snoozed_until'] ?? null;

    return [
        'interval' => $interval,
        'use_by_date' => $use_by,
        'days_until' => $days_until,
        'status' => match (true) {
            $days_until === null => null,
            $days_until < 0 => 'overdue',
            $days_until === 0 => 'due_today',
            default => 'upcoming',
        },
        'is_snoozed' => $snoozed_until !== null && $snoozed_until !== '' && $snoozed_until > $today,
    ];
}

/**
 * The interval a page views the queue with, from the $value it was given: a
 * numeric value as the number it is, anything else $default_interval.
 */
function use_by_view_interval($value, $default_interval) {
    return is_numeric($value) ? 0 + $value : $default_interval;
}

/**
 * use_by_status()'s use-by date as Y-m-d, or null when there is no basis for
 * one (no valid acquisition date and no use).
 *
 * Never used, or last used before acquisition: acquisition + $interval.
 * Otherwise: last use + 2 × $interval. Intervals are days and keep their
 * fraction, rounded to the nearest hour.
 *
 * Dates are read and added to in UTC so a daylight-saving change cannot move
 * the result a day.
 */
function use_by_date($acquired, $last_use, float $interval) {
    // Midnight UTC on the date that starts $value, or null if it holds no valid date.
    $parse = static function ($value) {
        $date = substr((string) $value, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    };
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

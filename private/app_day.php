<?php

/**
 * App day: the calendar day in America/New_York that Keeplore counts today
 * from. Use-by dates, snooze-until dates, the Daily email's hour, the dates
 * forms start with, people-list age, the person page's age, the latest birth
 * year People accepts, an undated Event's year, and the day the Candidates
 * page and the uses-list hint count an interval back from all read it here.
 * That interval is the owner's default use interval. Nothing else names the
 * time zone or changes the process default. The footer copyright year stays
 * on the server clock, and log timestamps stay instants.
 */

/** The time zone the app's day is counted in. */
const APP_TIME_ZONE = 'America/New_York';

/** Today's date (Y-m-d) in the app's day. */
function app_today(): string
{
    return app_now()->format('Y-m-d');
}

/** The year of the app day. */
function app_year(): int
{
    return (int) app_now()->format('Y');
}

/** The current moment in the app's time zone. */
function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIME_ZONE));
}

/**
 * The calendar day $days after midnight UTC on $day (Y-m-d). Negative $days
 * counts back. $days is taken as hours, rounded to the nearest hour, so a
 * daylight-saving change cannot move the result a day. A use-by date adds
 * its interval through here. A $day that is not a real calendar day is refused.
 */
function app_shift_day(string $day, float $days): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
    if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
        throw new InvalidArgumentException('Not a real calendar day.');
    }
    $hours = (int) round($days * 24);

    return $parsed->modify(sprintf('%+d hours', $hours))->format('Y-m-d');
}

/**
 * The calendar day $days before $day (Y-m-d). A $day that is not a real
 * calendar day is refused; callers pass the app day. A silent fallback
 * would hide a bad caller.
 */
function app_days_before(string $day, float $days): string
{
    return app_shift_day($day, -$days);
}

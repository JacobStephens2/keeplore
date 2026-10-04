<?php

/**
 * App day: the calendar day in America/New_York that Keeplore counts today
 * from. Use-by dates, snooze-until dates, the Daily email's hour and the
 * dates forms start with all read it here; nothing else names the time zone
 * or changes the process default.
 */

/** The time zone the app's day is counted in. */
const APP_TIME_ZONE = 'America/New_York';

/** Today's date (Y-m-d) in the app's day. */
function app_today(): string
{
    return app_now()->format('Y-m-d');
}

/** The current moment in the app's time zone. */
function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIME_ZONE));
}

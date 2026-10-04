<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/use_by_date.php';

/**
 * The use-by date ("Interact by" in the UI): every page that shows when an
 * item should next be used gets it from use_by_date().
 */
class UseByDateTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'never used: acquisition + interval' => ['2024-01-10', null, null, 90, '2024-04-09'],
            'used after acquisition: last use + 2 × interval' => ['2020-01-01', '2024-03-01', null, 90, '2024-08-28'],
            'used before acquisition: acquisition + interval' => ['2024-01-10', '2023-12-25', null, 90, '2024-04-09'],
            'used on the acquisition date counts as a use' => ['2024-01-10', '2024-01-10', null, 90, '2024-07-08'],
            'own frequency wins over the default' => ['2024-01-10', null, '30', 90, '2024-02-09'],
            'own frequency doubles after a use' => ['2020-01-01', '2024-03-01', '30.00', 90, '2024-04-30'],
            'fractional interval keeps its fraction' => ['2024-01-10', null, '0.5', 90, '2024-01-10'],
            'fractional interval doubled after a use' => ['2020-01-01', '2024-03-01', '0.5', 90, '2024-03-02'],
            'fractional default interval' => ['2024-01-10', null, null, 1.5, '2024-01-11'],
            'across the November fall-back the date holds' => ['2024-11-02', null, null, 2, '2024-11-04'],
            'across the November fall-back after a use' => ['2020-01-01', '2024-11-02', null, 1, '2024-11-04'],
            'datetime values are read by their date' => ['2024-01-10 00:00:00', '2024-03-01 18:30:00', null, 90, '2024-08-28'],
            'no acquisition date and no use: no basis' => [null, null, null, 90, null],
            'empty acquisition date and no use: no basis' => ['', null, null, 90, null],
            'invalid acquisition date and no use: no basis' => ['not-a-date', null, null, 90, null],
            'zero acquisition date and no use: no basis' => ['0000-00-00', null, null, 90, null],
            'no acquisition date with a use: last use + 2 × interval' => [null, '2024-03-01', null, 90, '2024-08-28'],
            'invalid acquisition date with a use: last use + 2 × interval' => ['not-a-date', '2024-03-01', null, 90, '2024-08-28'],
            'empty last use counts as never used' => ['2024-01-10', '', null, 90, '2024-04-09'],
        ];
    }

    #[DataProvider('cases')]
    public function test_use_by_date($acquired, $last_use, $frequency, $default_interval, $expected): void
    {
        $this->assertSame($expected, use_by_date($acquired, $last_use, $frequency, $default_interval));
    }

    public function test_the_date_does_not_depend_on_the_server_time_zone(): void
    {
        $zone = date_default_timezone_get();
        try {
            foreach (['America/New_York', 'Pacific/Auckland', 'UTC'] as $tz) {
                date_default_timezone_set($tz);
                $this->assertSame('2024-11-04', use_by_date('2024-11-02', null, null, 2), $tz);
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    private static function item(array $columns = []): array
    {
        return $columns + ['Acq' => '2024-01-10', 'last_use' => null, 'interaction_frequency_days' => null, 'snoozed_until' => null];
    }

    public function test_the_items_own_frequency_is_its_interval(): void
    {
        $status = use_by_status(self::item(['interaction_frequency_days' => '14.00']), 90, '2024-01-10');
        $this->assertSame(14.0, $status['interval']);
        $this->assertSame('2024-01-24', $status['use_by_date']);
    }

    public function test_without_its_own_frequency_the_default_is_its_interval(): void
    {
        $status = use_by_status(self::item(), '90', '2024-01-10');
        $this->assertSame(90.0, $status['interval']);
        $this->assertSame('2024-04-09', $status['use_by_date']);
    }

    public static function statuses(): array
    {
        return [
            'months before: upcoming' => ['2024-01-10', 90, 'upcoming'],
            'a day before: upcoming' => ['2024-04-08', 1, 'upcoming'],
            'on the use-by date: due today' => ['2024-04-09', 0, 'due_today'],
            'a day after: overdue' => ['2024-04-10', -1, 'overdue'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_and_days_until_turn_on_today(string $today, int $days, string $status): void
    {
        $answer = use_by_status(self::item(), 90, $today);
        $this->assertSame('2024-04-09', $answer['use_by_date']);
        $this->assertSame($days, $answer['days_until']);
        $this->assertSame($status, $answer['status']);
    }

    public function test_days_until_counts_whole_days_across_a_daylight_saving_change(): void
    {
        $zone = date_default_timezone_get();
        try {
            foreach (['America/New_York', 'Pacific/Auckland', 'UTC'] as $tz) {
                date_default_timezone_set($tz);
                $spring = use_by_status(self::item(['Acq' => '2024-03-01']), 30, '2024-03-09');
                $this->assertSame(['2024-03-31', 22, 'upcoming'], [$spring['use_by_date'], $spring['days_until'], $spring['status']], $tz);
                $fall = use_by_status(self::item(['Acq' => '2024-10-01']), 30, '2024-11-04');
                $this->assertSame(['2024-10-31', -4, 'overdue'], [$fall['use_by_date'], $fall['days_until'], $fall['status']], $tz);
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_snoozed_while_the_snoozed_until_date_is_after_today(): void
    {
        $item = self::item(['snoozed_until' => '2024-02-01']);
        $this->assertTrue(use_by_status($item, 90, '2024-01-31')['is_snoozed']);
        $this->assertFalse(use_by_status($item, 90, '2024-02-01')['is_snoozed'], 'An item snoozed until today shows again today.');
        $this->assertFalse(use_by_status($item, 90, '2024-02-02')['is_snoozed']);
        $this->assertFalse(use_by_status(self::item(), 90, '2024-01-31')['is_snoozed']);
    }

    public function test_no_acquisition_date_and_no_use_has_no_use_by_date(): void
    {
        $this->assertSame([
            'interval' => 90.0,
            'use_by_date' => null,
            'days_until' => null,
            'status' => null,
            'is_snoozed' => false,
        ], use_by_status(self::item(['Acq' => null]), 90, '2024-01-10'));
    }
}

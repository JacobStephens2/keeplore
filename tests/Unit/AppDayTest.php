<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/app_day.php';

/**
 * The App day: the calendar day in America/New_York that Keeplore counts
 * today from. Every reader of today goes through private/app_day.php.
 */
class AppDayTest extends TestCase
{
    public function test_today_and_now_do_not_depend_on_the_server_time_zone(): void
    {
        $zone = date_default_timezone_get();
        try {
            foreach (['America/New_York', 'Pacific/Auckland', 'UTC'] as $tz) {
                date_default_timezone_set($tz);
                $newYork = new \DateTimeImmutable('now', new \DateTimeZone('America/New_York'));
                $this->assertSame($newYork->format('Y-m-d'), app_today(), $tz);
                $this->assertSame((int) $newYork->format('Y'), app_year(), $tz);
                $now = app_now();
                $this->assertSame('America/New_York', $now->getTimezone()->getName(), $tz);
                $this->assertLessThan(5, abs($now->getTimestamp() - $newYork->getTimestamp()), $tz);
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_only_the_app_day_module_names_the_time_zone_or_sets_the_process_default(): void
    {
        $offending = [];
        foreach (['private', 'ui', 'api', 'bin'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PROJECT_PATH . '/' . $root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = substr($file->getPathname(), strlen(PROJECT_PATH));
                if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/') || $path === '/private/app_day.php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (str_contains($source, 'America/New_York') || str_contains($source, 'date_default_timezone_set')) {
                    $offending[] = $path;
                }
            }
        }

        $this->assertSame([], $offending);
    }

    public function test_production_code_does_not_read_todays_year_or_now_minus_an_interval_from_the_server_clock(): void
    {
        $offending = [];
        foreach (['private', 'ui', 'api', 'bin'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PROJECT_PATH . '/' . $root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = substr($file->getPathname(), strlen(PROJECT_PATH));
                if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/')) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $readsYear = $path !== '/private/shared/footer.php'
                    && preg_match("/date\\(\\s*(['\"])Y\\1\\s*\\)/", $source) === 1;
                $asksNowMinusInterval = preg_match('/strtotime\\s*\\([^;\\n]*(?:days ago|now\\s*-)/', $source) === 1
                    || str_contains($source, 'DEFAULT_USE_INTERVAL');
                if ($readsYear || $asksNowMinusInterval) {
                    $offending[] = $path;
                }
            }
        }

        $this->assertSame([], $offending);
    }

    public static function daysBefore(): array
    {
        return [
            '90 days before 2026-10-22' => ['2026-10-22', 90, '2026-07-24'],
            '90.5 days before 2026-10-22' => ['2026-10-22', 90.5, '2026-07-23'],
            '1 day before 2026-03-09' => ['2026-03-09', 1, '2026-03-08'],
            'half a day before 2026-03-09' => ['2026-03-09', 0.5, '2026-03-08'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daysBefore')]
    public function test_a_number_of_days_before_a_day_is_that_calendar_day(string $day, float $days, string $expected): void
    {
        $this->assertSame($expected, app_days_before($day, $days));
    }

    public static function unrealDays(): array
    {
        return [
            'not a day' => ['2026-02-31'],
            'not a month' => ['2026-13-01'],
            'blank' => [''],
            'a date and a time' => ['2026-10-22 00:00:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrealDays')]
    public function test_days_before_refuses_a_day_that_is_not_a_real_calendar_day(string $day): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app_days_before($day, 1);
    }
}

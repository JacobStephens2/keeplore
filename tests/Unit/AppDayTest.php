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
}

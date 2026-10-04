<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A queued import only runs if cron starts the worker from the live release,
 * and Settings only shows progress if it can reach the status endpoint.
 */
final class BggImportJobsWiringTest extends TestCase
{
    public function test_crontab_copy_runs_the_worker_every_minute_from_the_live_release(): void
    {
        $copy = (string) file_get_contents(PRIVATE_PATH . '/crons/crontab-copy.txt');

        $this->assertFileExists(PRIVATE_PATH . '/crons/run_bgg_import_jobs.php');
        $this->assertStringContainsString(
            '* * * * * /usr/bin/php /opt/keeplore/current/private/crons/run_bgg_import_jobs.php >> /opt/keeplore/shared/logs/run_bgg_import_jobs.php.log 2>&1',
            $copy
        );
    }

    public function test_settings_queues_an_import_and_watches_it(): void
    {
        $settings = (string) file_get_contents(PROJECT_PATH . '/ui/settings/edit.php');
        $queue = (string) file_get_contents(PROJECT_PATH . '/ui/settings/bgg-import.php');
        $status = (string) file_get_contents(PROJECT_PATH . '/ui/settings/bgg-import-status.php');

        $this->assertStringContainsString("url_for('/settings/bgg-import.php')", $settings);
        $this->assertStringContainsString("url_for('/settings/bgg-import-status.php')", $settings);
        $this->assertStringContainsString("url_for('/settings/bgg-import.js')", $settings);
        $this->assertStringContainsString('->queue()', $queue);
        $this->assertStringContainsString('->status()', $status);
        $this->assertStringContainsString('->status()', $settings);
        foreach ([$settings, $queue, $status] as $page) {
            $this->assertStringContainsString('new BggImports(', $page);
        }
        foreach ([$queue, $status] as $page) {
            $this->assertStringContainsString('require_login()', $page);
        }
    }
}

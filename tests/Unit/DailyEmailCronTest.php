<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The daily use-by email only goes out if cron can still find the script
 * after a site rename. crontab-copy.txt is the documented job; retired
 * document-root names mean the hourly send silently no-ops.
 */
class DailyEmailCronTest extends TestCase
{
    private string $crontabCopy;
    private string $cronScript;

    protected function setUp(): void
    {
        $this->crontabCopy = PRIVATE_PATH . '/crons/crontab-copy.txt';
        $this->cronScript = PRIVATE_PATH . '/crons/email_notification_of_todays_use_bys.php';
    }

    public function test_cron_script_is_present(): void
    {
        $this->assertFileExists($this->cronScript);
    }

    public function test_crontab_copy_targets_the_live_keeplore_release_not_retired_paths(): void
    {
        $this->assertFileExists($this->crontabCopy);
        $copy = file_get_contents($this->crontabCopy);
        $this->assertNotFalse($copy);

        $this->assertStringContainsString(
            '/opt/keeplore/current/private/crons/email_notification_of_todays_use_bys.php',
            $copy,
            'Hourly cron must run the promoted release, which survives document-root renames.'
        );
        $this->assertStringContainsString(
            '/opt/keeplore/shared/logs/email_notification_of_todays_use_bys.php.log',
            $copy,
            'Cron stdout must land on the shared log volume; the release tree is not writable.'
        );
        $this->assertStringNotContainsString('artifact-management-tool', $copy);
        $this->assertStringNotContainsString('artifact.stephens.page', $copy);
    }
}

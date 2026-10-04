<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preferences;

/**
 * Seam: the Preferences interface, the owner's own defaults and reminders.
 * The fixture's users table carries the preference columns as
 * database/local-schema.sql defines them; owners 1 and 2 start with the
 * column defaults.
 */
final class PreferencesTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Preferences $preferences;

    private const DEFAULTS = [
        'default_use_interval' => 90.0,
        'default_snooze_days' => 7,
        'default_setting' => '',
        'daily_email' => true,
        'daily_email_hour' => 8,
        'native_notify_enabled' => true,
        'native_notify_hour' => 9,
        'native_notify_lead_days' => 3,
        'native_notify_past_due' => true,
    ];

    protected function setUp(): void
    {
        if (!getenv('KEEPLORE_TEST_DB_HOST')) {
            $this->markTestSkipped('Set KEEPLORE_TEST_DB_HOST to run MySQL integration tests.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli(
            getenv('KEEPLORE_TEST_DB_HOST'),
            getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            '',
            (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306)
        );
        $this->databaseName = 'keeplore_test_' . bin2hex(random_bytes(6));
        $this->db->query('CREATE DATABASE ' . $this->databaseName);
        $this->db->select_db($this->databaseName);
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        require_once PRIVATE_PATH . '/classes/Preferences.php';
        $this->preferences = new Preferences($this->db, 1);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function runSql(string $sql): void
    {
        $this->db->multi_query($sql);
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    public function test_null_columns_read_as_their_defaults(): void
    {
        $this->db->query('UPDATE users SET default_use_interval = NULL, default_setting = NULL WHERE id = 1');

        $this->assertSame(self::DEFAULTS, $this->preferences->get());
    }

    public function test_out_of_range_stored_values_read_as_their_defaults(): void
    {
        $this->db->query('UPDATE users SET default_use_interval = 0, default_snooze_days = 0,
            daily_email_hour = 24, native_notify_hour = 99, native_notify_lead_days = 15 WHERE id = 1');

        $this->assertSame(self::DEFAULTS, $this->preferences->get());
    }

    public function test_an_owner_with_no_row_gets_every_default(): void
    {
        $this->assertSame(self::DEFAULTS, (new Preferences($this->db, 999))->get());
    }

    public function test_get_returns_the_stored_values_typed(): void
    {
        $this->db->query("UPDATE users SET default_use_interval = 30, default_snooze_days = 14,
            default_setting = 'Kitchen table', daily_email = 0, daily_email_hour = 0,
            native_notify_enabled = 0, native_notify_hour = 23, native_notify_lead_days = 0,
            native_notify_past_due = 0 WHERE id = 1");

        $this->assertSame([
            'default_use_interval' => 30.0,
            'default_snooze_days' => 14,
            'default_setting' => 'Kitchen table',
            'daily_email' => false,
            'daily_email_hour' => 0,
            'native_notify_enabled' => false,
            'native_notify_hour' => 23,
            'native_notify_lead_days' => 0,
            'native_notify_past_due' => false,
        ], $this->preferences->get());
    }

    public function test_save_takes_form_values_and_returns_them_as_stored(): void
    {
        $saved = $this->preferences->save([
            'default_use_interval' => '30',
            'default_snooze_days' => '365',
            'default_setting' => 'Home',
            'daily_email' => false,
            'daily_email_hour' => '23',
            'native_notify_enabled' => '0',
            'native_notify_hour' => '0',
            'native_notify_lead_days' => '14',
            'native_notify_past_due' => '1',
        ]);

        $expected = [
            'default_use_interval' => 30.0,
            'default_snooze_days' => 365,
            'default_setting' => 'Home',
            'daily_email' => false,
            'daily_email_hour' => 23,
            'native_notify_enabled' => false,
            'native_notify_hour' => 0,
            'native_notify_lead_days' => 14,
            'native_notify_past_due' => true,
        ];
        $this->assertSame($expected, $saved);
        $this->assertSame($expected, $this->preferences->get());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRangeProvider')]
    public function test_a_missing_empty_or_out_of_range_value_saves_as_the_default(string $preference, $value): void
    {
        $this->preferences->save(['default_use_interval' => 30, 'default_snooze_days' => 14,
            'daily_email_hour' => 20, 'native_notify_hour' => 20, 'native_notify_lead_days' => 10]);

        $saved = $this->preferences->save([$preference => $value]);

        $this->assertSame(self::DEFAULTS[$preference], $saved[$preference]);
        $this->assertSame(self::DEFAULTS[$preference], $this->preferences->get()[$preference]);
    }

    public static function outOfRangeProvider(): array
    {
        return [
            'interval below 1' => ['default_use_interval', '0.5'],
            'interval not a number' => ['default_use_interval', 'soon'],
            'interval empty' => ['default_use_interval', ''],
            'interval null' => ['default_use_interval', null],
            'snooze below 1' => ['default_snooze_days', '0'],
            'snooze above 365' => ['default_snooze_days', '366'],
            'snooze fractional' => ['default_snooze_days', '7.5'],
            'email hour above 23' => ['daily_email_hour', '24'],
            'email hour negative' => ['daily_email_hour', '-1'],
            'notify hour empty' => ['native_notify_hour', ''],
            'lead days above 14' => ['native_notify_lead_days', '15'],
        ];
    }

    public function test_save_leaves_unnamed_preferences_unchanged(): void
    {
        $this->preferences->save(['default_snooze_days' => 14, 'default_setting' => 'Home', 'daily_email' => false]);

        $saved = $this->preferences->save(['default_setting' => 'Cabin']);

        $this->assertSame(14, $saved['default_snooze_days']);
        $this->assertSame('Cabin', $saved['default_setting']);
        $this->assertFalse($saved['daily_email']);
    }

    public function test_a_fractional_interval_is_kept(): void
    {
        $this->assertSame(45.5, $this->preferences->save(['default_use_interval' => '45.5'])['default_use_interval']);
        $this->assertSame(45.5, $this->preferences->get()['default_use_interval']);
    }

    public function test_save_refuses_a_preference_it_does_not_know_and_writes_nothing(): void
    {
        try {
            $this->preferences->save(['default_snooze_days' => 14, 'first_name' => 'Mallory']);
            $this->fail('An unknown preference was accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Unknown preference: first_name', $error->getMessage());
        }
        $this->assertSame(7, $this->preferences->get()['default_snooze_days']);
    }

    public function test_another_owners_preferences_are_untouched(): void
    {
        $this->preferences->save(['default_use_interval' => 30, 'default_setting' => 'Home', 'daily_email' => false]);

        $this->assertSame(self::DEFAULTS, (new Preferences($this->db, 2))->get());
    }

    public function test_owners_due_the_daily_email_are_those_opted_in_at_that_hour(): void
    {
        $this->db->query('INSERT INTO users (id) VALUES (3), (4)');
        (new Preferences($this->db, 1))->save(['daily_email' => true, 'daily_email_hour' => 6]);
        (new Preferences($this->db, 2))->save(['daily_email' => false, 'daily_email_hour' => 6]);
        (new Preferences($this->db, 3))->save(['daily_email' => true, 'daily_email_hour' => 7]);

        $this->assertSame([1], Preferences::ownersDueDailyEmailAt($this->db, 6));
        $this->assertSame([3], Preferences::ownersDueDailyEmailAt($this->db, 7));
        $this->assertSame([4], Preferences::ownersDueDailyEmailAt($this->db, 8));
        $this->assertSame([], Preferences::ownersDueDailyEmailAt($this->db, 9));
    }

    public function test_a_stored_out_of_range_email_hour_sends_at_the_default_hour(): void
    {
        $this->db->query('UPDATE users SET daily_email_hour = 30 WHERE id = 1');
        $this->db->query('UPDATE users SET daily_email = 0 WHERE id = 2');

        $this->assertSame([1], Preferences::ownersDueDailyEmailAt($this->db, 8));
        $this->assertSame([], Preferences::ownersDueDailyEmailAt($this->db, 30));
    }
}

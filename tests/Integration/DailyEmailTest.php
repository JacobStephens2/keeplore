<?php

namespace Tests\Integration;

use DailyEmail;
use Mailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once PRIVATE_PATH . '/classes/Mailer.php';

final class DailyEmailTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private RecordingMailer $mailer;

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
        require_once PRIVATE_PATH . '/classes/DailyEmail.php';
        defined('DOMAIN') || define('DOMAIN', 'keeplore.app');
        defined('DEV_EMAIL') || define('DEV_EMAIL', 'dev@keeplore.app');

        // Catan (item 10) is next due 2026-07-31, outside the email's week.
        $this->db->query("UPDATE users SET email = 'owner@keeplore.app' WHERE id = 1");
        $this->db->query("UPDATE users SET email = 'other@keeplore.app' WHERE id = 2");
        $this->mailer = new RecordingMailer();
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

    private function send(int $userId = 1): int
    {
        return (new DailyEmail($this->db, $userId, $this->mailer, '2026-06-01'))->send();
    }

    /** An owner's item, never used, acquired on $acquired with its own frequency (null for the default). */
    private function addItem(int $id, string $title, string $acquired, ?int $frequency = 10, int $userId = 1): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO games (id, user_id, Title, type_id, Acq, interaction_frequency_days) VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->bind_param('iissi', $id, $userId, $title, $acquired, $frequency);
        $stmt->execute();
        $stmt->close();
    }

    private function addUse(int $itemId, string $date, int $userId = 1): void
    {
        $stmt = $this->db->prepare('INSERT INTO uses (artifact_id, user_id, use_date) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $itemId, $userId, $date);
        $stmt->execute();
        $stmt->close();
    }

    /** The one email sent, as [to, subject, html]. */
    private function onlyEmail(): array
    {
        $this->assertCount(1, $this->mailer->sent);
        return $this->mailer->sent[0];
    }

    /** The body's text between one section heading and the next. */
    private function section(string $html, string $heading): string
    {
        $start = strpos($html, "<h1>$heading</h1>");
        $this->assertNotFalse($start, "Missing section $heading");
        $end = strpos($html, '<h1>', $start + 1);
        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_nothing_due_sends_nothing(): void
    {
        $this->assertSame(0, $this->send());
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_an_overdue_item_is_listed_with_snooze_and_get_rid_of(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-21');

        $this->assertSame(1, $this->send());
        [$to, $subject, $html] = $this->onlyEmail();
        $this->assertSame('owner@keeplore.app', $to);
        $this->assertSame('Interactions Due', $subject);
        $overdue = $this->section($html, 'Interactions overdue');
        $this->assertStringContainsString('Ticket to Ride', $overdue);
        $this->assertStringContainsString('No interactions, interact by 2026-05-31 (Sunday, interval: 10 days)', $overdue);
        $this->assertStringContainsString('https://keeplore.app/artifacts/snooze.php?artifact_id=30&return_to=useby', $overdue);
        $this->assertStringContainsString('https://keeplore.app/artifacts/mark-get-rid-of.php?artifact_id=30&return_to=useby', $overdue);
        $this->assertStringContainsString('https://keeplore.app/uses/record-new?artifact_id=30', $overdue);
        $this->assertStringNotContainsString('artifact_name', $html);
    }

    public function test_an_item_due_today_is_listed_with_its_last_use(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-01-01');
        $this->addUse(30, '2026-05-12');

        $this->assertSame(1, $this->send());
        [, , $html] = $this->onlyEmail();
        $dueToday = $this->section($html, 'Interactions due today');
        $this->assertStringContainsString('Ticket to Ride', $dueToday);
        $this->assertStringContainsString('last interacted 2026-05-12 (interval: 10 days)', $dueToday);
        $this->assertStringContainsString('snooze.php?artifact_id=30&return_to=useby', $dueToday);
        $this->assertStringNotContainsString('mark-get-rid-of.php', $dueToday);
        $this->assertStringNotContainsString('Interactions overdue', $html);
    }

    public function test_the_coming_week_runs_seven_days_and_leaves_out_the_eighth(): void
    {
        $this->addItem(30, 'Seven days out', '2026-05-29');
        $this->addItem(31, 'Eight days out', '2026-05-30');

        $this->assertSame(1, $this->send());
        [, , $html] = $this->onlyEmail();
        $comingWeek = $this->section($html, 'Interactions due in coming week');
        $this->assertStringContainsString('Seven days out', $comingWeek);
        $this->assertStringContainsString('interact by 2026-06-08 (Monday, interval: 10 days)', $comingWeek);
        $this->assertStringNotContainsString('mark-get-rid-of.php', $comingWeek);
        $this->assertStringNotContainsString('Eight days out', $html);
    }

    public function test_the_summary_counts_each_section_and_the_total(): void
    {
        $this->addItem(30, 'Overdue one', '2026-05-01');
        $this->addItem(31, 'Overdue two', '2026-05-02');
        $this->addItem(32, 'Due today', '2026-05-22');
        $this->addItem(33, 'Upcoming', '2026-05-25');

        $this->assertSame(4, $this->send());
        [, , $html] = $this->onlyEmail();
        $this->assertStringContainsString('<strong>4</strong> items need attention.', $html);
        $this->assertMatchesRegularExpression('#>Overdue</td>\s*<td[^>]*>2</td>#', $html);
        $this->assertMatchesRegularExpression('#>Due today</td>\s*<td[^>]*>1</td>#', $html);
        $this->assertMatchesRegularExpression('#>Due in the coming week</td>\s*<td[^>]*>1</td>#', $html);
        $this->assertLessThan(strpos($html, 'Interactions due today'), strpos($html, 'Interactions overdue'));
        $this->assertLessThan(strpos($html, 'Interactions due in coming week'), strpos($html, 'Interactions due today'));
    }

    public function test_one_item_reads_in_the_singular(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-22');

        $this->send();
        $this->assertStringContainsString('<strong>1</strong> item needs attention.', $this->onlyEmail()[2]);
    }

    public function test_each_section_lists_never_used_items_first_then_by_last_use(): void
    {
        // All overdue, and by use-by date the reverse: Used recently on
        // 2026-05-14, Used long ago on 2026-05-20, Never used on 2026-05-31.
        $this->addItem(30, 'Used recently', '2026-01-01', 2);
        $this->addUse(30, '2026-05-10');
        $this->addItem(31, 'Used long ago', '2026-01-01', 40);
        $this->addUse(31, '2026-03-01');
        $this->addItem(32, 'Never used', '2026-05-21');

        $this->assertSame(3, $this->send());
        $overdue = $this->section($this->onlyEmail()[2], 'Interactions overdue');
        $never = strpos($overdue, 'Never used');
        $longAgo = strpos($overdue, 'Used long ago');
        $recently = strpos($overdue, 'Used recently');
        $this->assertLessThan($longAgo, $never);
        $this->assertLessThan($recently, $longAgo);
    }

    public function test_an_item_without_its_own_frequency_shows_the_owners_default_interval(): void
    {
        (new \Preferences($this->db, 1))->save(['default_use_interval' => 30]);
        $this->addItem(30, 'Own frequency', '2026-05-25', 12);
        $this->addItem(31, 'Default interval', '2026-05-05', null);

        $this->assertSame(2, $this->send());
        $comingWeek = $this->section($this->onlyEmail()[2], 'Interactions due in coming week');
        $this->assertStringContainsString('interact by 2026-06-06 (Saturday, interval: 12 days)', $comingWeek);
        $this->assertStringContainsString('interact by 2026-06-04 (Thursday, interval: 30 days)', $comingWeek);
    }

    public function test_an_item_to_get_rid_of_is_left_out(): void
    {
        $this->addItem(30, 'Leaving', '2026-05-01');
        $this->db->query('UPDATE games SET to_get_rid_of = 1 WHERE id = 30');

        $this->assertSame(0, $this->send());
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_an_owner_without_an_address_gets_nothing(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-01');
        $this->db->query('UPDATE users SET email = NULL WHERE id = 1');

        $this->assertSame(0, $this->send());
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_an_address_on_a_reserved_tld_gets_nothing(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-01');
        foreach (['demo@artifact.example', 'a@b.test', 'a@b.INVALID', 'a@localhost.localhost'] as $address) {
            $stmt = $this->db->prepare('UPDATE users SET email = ? WHERE id = 1');
            $stmt->bind_param('s', $address);
            $stmt->execute();
            $stmt->close();

            $this->assertSame(0, $this->send(), $address);
        }
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_a_title_is_escaped_once(): void
    {
        $this->addItem(30, 'Tom & Jerry <Deluxe>', '2026-05-01');

        $this->send();
        $html = $this->onlyEmail()[2];
        $this->assertStringContainsString('Tom &amp; Jerry &lt;Deluxe&gt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<Deluxe>', $html);
    }

    public function test_a_mailer_failure_is_reported_to_the_developer_not_the_owner(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-01');
        $this->mailer->failFor('owner@keeplore.app', 'SMTP said <no> & left');

        $this->assertSame(1, $this->send());
        [$to, $subject, $html] = $this->onlyEmail();
        $this->assertSame('dev@keeplore.app', $to);
        $this->assertSame('Error with Keeplore Uses Due Today Email', $subject);
        $this->assertStringContainsString('owner 1', $html);
        $this->assertStringContainsString('SMTP said &lt;no&gt; &amp; left', $html);
        $this->assertStringNotContainsString('RuntimeException Object', $html);
        $this->assertStringNotContainsString('Ticket to Ride', $html);
    }

    public function test_a_failed_report_is_logged_and_the_count_still_returned(): void
    {
        $this->addItem(30, 'Ticket to Ride', '2026-05-01');
        $this->mailer->failFor('owner@keeplore.app', 'owner send failed');
        $this->mailer->failFor('dev@keeplore.app', 'report send failed');
        $log = tempnam(sys_get_temp_dir(), 'daily-email-log');
        $previousLog = ini_set('error_log', $log);

        try {
            $this->assertSame(1, $this->send());
        } finally {
            ini_set('error_log', $previousLog);
        }
        $logged = file_get_contents($log);
        unlink($log);
        $this->assertSame([], $this->mailer->sent);
        $this->assertStringContainsString('owner 1', $logged);
        $this->assertStringContainsString('owner send failed', $logged);
        $this->assertStringContainsString('report send failed', $logged);
    }

    public function test_another_owners_items_never_appear(): void
    {
        // Item 20, owner 2's Private item, is overdue since 2026-04-01.
        $this->addItem(30, 'Ticket to Ride', '2026-05-01');

        $this->assertSame(1, $this->send());
        [$to, , $html] = $this->onlyEmail();
        $this->assertSame('owner@keeplore.app', $to);
        $this->assertStringNotContainsString('Private item', $html);

        $this->assertSame(1, $this->send(2));
        [$to, , $html] = $this->mailer->sent[1];
        $this->assertSame('other@keeplore.app', $to);
        $this->assertStringContainsString('Private item', $html);
        $this->assertStringNotContainsString('Ticket to Ride', $html);
    }
}

/** Records each email it is asked to send; throws for addresses told to fail. */
final class RecordingMailer implements Mailer
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $sent = [];

    /** @var array<string, string> address => exception message */
    private array $failures = [];

    public function failFor(string $address, string $message): void
    {
        $this->failures[$address] = $message;
    }

    public function send(string $to, string $subject, string $html): void
    {
        if (isset($this->failures[$to])) {
            throw new RuntimeException($this->failures[$to]);
        }
        $this->sent[] = [$to, $subject, $html];
    }
}

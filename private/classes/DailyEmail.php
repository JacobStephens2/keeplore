<?php

require_once dirname(__DIR__) . '/functions.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/UseByQueue.php';

/**
 * The owner's Daily email: their overdue items, items due today and items
 * due in the coming week from the Use-by queue, each section by last use
 * with never-used items first. Nothing is sent when nothing is due, or when
 * the owner has no address that can receive mail.
 */
final class DailyEmail
{
    private const SUBJECT = 'Interactions Due';
    private const FAILURE_SUBJECT = 'Error with Keeplore Uses Due Today Email';

    /**
     * Each section in order: the queue status it lists and how many days out
     * it reaches, its heading, its summary label and style, and whether its
     * items offer Get Rid Of and show their use-by date.
     */
    private const SECTIONS = [
        'overdue' => ['status' => 'overdue', 'within_days' => null, 'heading' => 'Interactions overdue', 'label' => 'Overdue', 'label_style' => 'font-weight:bold;color:#b63d2f;', 'get_rid_of' => true, 'use_by' => true],
        'due_today' => ['status' => 'due_today', 'within_days' => null, 'heading' => 'Interactions due today', 'label' => 'Due today', 'label_style' => 'font-weight:bold;', 'get_rid_of' => false, 'use_by' => false],
        'coming_week' => ['status' => 'upcoming', 'within_days' => 7, 'heading' => 'Interactions due in coming week', 'label' => 'Due in the coming week', 'label_style' => 'font-weight:bold;', 'get_rid_of' => false, 'use_by' => true],
    ];

    /** $today is a Y-m-d day; the App day (app_today()) when omitted. */
    public function __construct(private mysqli $db, private int $userId, private Mailer $mailer, private ?string $today = null)
    {
    }

    /**
     * Sends the email and returns how many items it told about, or 0 when
     * none was sent. A mail failure is reported to the developer instead,
     * never thrown, and the count still returned.
     */
    public function send(): int
    {
        $entriesBySection = $this->entriesBySection();
        $count = self::count($entriesBySection);
        $address = $count > 0 ? $this->address() : null;
        if ($address === null) {
            return 0;
        }

        try {
            $this->mailer->send($address, self::SUBJECT, $this->body($entriesBySection));
        } catch (RuntimeException $failure) {
            $this->reportFailure($failure);
        }
        return $count;
    }

    /**
     * The queue's entries in each section, by last use with never used
     * first.
     */
    private function entriesBySection(): array
    {
        $entries = (new UseByQueue($this->db, $this->userId, $this->today))->entries();
        usort($entries, fn (array $a, array $b) => $a['last_use'] <=> $b['last_use']);

        $entriesBySection = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($entries as $entry) {
            foreach (self::SECTIONS as $key => $section) {
                if ($entry['status'] === $section['status']
                    && ($section['within_days'] === null || $entry['days_until'] <= $section['within_days'])) {
                    $entriesBySection[$key][] = $entry;
                }
            }
        }
        return $entriesBySection;
    }

    private static function count(array $entriesBySection): int
    {
        return array_sum(array_map('count', $entriesBySection));
    }

    /**
     * The owner's address, or null when they have none or it is on a
     * reserved TLD (RFC 2606, such as the seeded demo@artifact.example):
     * those always bounce, which erodes the domain's sender reputation.
     */
    private function address(): ?string
    {
        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $address = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));
        $stmt->close();
        if ($address === '' || preg_match('/\.(example|test|invalid|localhost)$/i', $address)) {
            return null;
        }
        return $address;
    }

    private function body(array $entriesBySection): string
    {
        $count = self::count($entriesBySection);
        $summaryRows = '';
        foreach (self::SECTIONS as $key => $section) {
            $summaryRows .= '
                <tr>
                  <td style="' . $section['label_style'] . '">' . $section['label'] . '</td>
                  <td style="font-weight:bold;">' . count($entriesBySection[$key]) . '</td>
                </tr>';
        }

        $body = '
            <h2 style="margin:0 0 0.5rem;">Summary</h2>
            <p style="margin:0 0 0.25rem;">
                <strong>' . $count . '</strong> ' . ($count === 1 ? 'item needs' : 'items need') . ' attention.
            </p>
            <p style="margin:0 0 0.75rem;">
                <a href="' . self::url('/artifacts/useby.php') . '">View interact by list</a>
            </p>
            <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:1.25rem;">' . $summaryRows . '
            </table>
            <hr>
        ';

        foreach (self::SECTIONS as $key => $section) {
            $body .= $this->renderSection($section, $entriesBySection[$key]);
        }

        return $body . '
            <p>Record uses at <a href="' . self::url('/uses/record-new.php') . '">' . DOMAIN . '</a></p>
        ';
    }

    /** One section's heading and list, or nothing when it has no items. */
    private function renderSection(array $section, array $entries): string
    {
        if (!$entries) {
            return '';
        }
        $site = self::url('');

        $items = '';
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            $interval = $entry['interval'];
            $lastUse = $entry['last_use'] === null ? 'No interactions' : 'last interacted ' . $entry['last_use'];
            $timing = $section['use_by']
                ? "$lastUse, interact by {$entry['use_by_date']} (" . date('l', strtotime($entry['use_by_date'])) . ", interval: $interval days)"
                : "$lastUse (interval: $interval days)";
            $actions = "<a href='$site/uses/record-new?artifact_id=$id'>Record Interaction</a>
                    | <a href='$site/artifacts/snooze.php?artifact_id=$id&return_to=useby'>Snooze</a>";
            if ($section['get_rid_of']) {
                $actions .= "
                    | <a href='$site/artifacts/mark-get-rid-of.php?artifact_id=$id&return_to=useby'>Get Rid Of</a>";
            }
            $items .= "
                <li>
                    <a href='$site/artifacts/edit.php?id=$id'>" . h($entry['Title']) . "</a>:
                    $actions
                    $timing
                </li>
            ";
        }

        return "
            <h1>{$section['heading']}</h1>
            <ul>$items</ul>
        ";
    }

    private static function url(string $path): string
    {
        return 'https://' . DOMAIN . $path;
    }

    /**
     * Tells the developer the email was not sent, with the owner and the
     * failure's message only, since a full dump can carry SMTP settings.
     * Logs it when even that fails.
     */
    private function reportFailure(RuntimeException $failure): void
    {
        $report = "The daily email to owner {$this->userId} was not sent: " . $failure->getMessage();
        try {
            $this->mailer->send(DEV_EMAIL, self::FAILURE_SUBJECT, '<p>' . h($report) . '</p>');
        } catch (RuntimeException $reportFailure) {
            error_log("$report. Reporting it to the developer failed too: " . $reportFailure->getMessage());
        }
    }
}

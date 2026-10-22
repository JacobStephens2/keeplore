<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/native_notification_plan.php';

/**
 * The native notification plan: reminder days are counted on the app day by
 * shifting the use-by date, with no database and no clock.
 */
class NativeNotificationPlanTest extends TestCase
{
    private static function prefs(int $lead = 3, bool $pastDue = true): array
    {
        return ['lead_days' => $lead, 'past_due' => $pastDue];
    }

    private static function entry(int|string|null $id, string|null $title, ?string $useBy, string $status = 'upcoming'): array
    {
        return ['id' => $id, 'Title' => $title, 'use_by_date' => $useBy, 'status' => $status];
    }

    public function test_a_not_overdue_item_gets_a_lead_a_due_and_an_overdue_reminder(): void
    {
        $reminders = native_notification_reminders(
            [self::entry(42, 'Catan', '2030-01-10', 'upcoming')],
            self::prefs(3, true)
        );

        $this->assertSame([
            ['id' => 421, 'title' => 'Due soon', 'body' => 'Catan is due 2030-01-10', 'day' => '2030-01-07', 'item_id' => 42],
            ['id' => 422, 'title' => 'Due today', 'body' => 'Catan is due today', 'day' => '2030-01-10', 'item_id' => 42],
            ['id' => 423, 'title' => 'Overdue', 'body' => 'Catan is overdue', 'day' => '2030-01-11', 'item_id' => 42],
        ], $reminders);
    }

    public function test_an_already_overdue_item_also_gets_a_catch_up_with_no_day(): void
    {
        $reminders = native_notification_reminders(
            [self::entry(73, 'Azul', '2029-12-01', 'overdue')],
            self::prefs(3, true)
        );

        $this->assertSame([
            ['id' => 731, 'title' => 'Due soon', 'body' => 'Azul is due 2029-12-01', 'day' => '2029-11-28', 'item_id' => 73],
            ['id' => 732, 'title' => 'Due today', 'body' => 'Azul is due today', 'day' => '2029-12-01', 'item_id' => 73],
            ['id' => 733, 'title' => 'Overdue', 'body' => 'Azul is overdue', 'day' => '2029-12-02', 'item_id' => 73],
            ['id' => 733, 'title' => 'Overdue', 'body' => 'Azul is overdue (due 2029-12-01)', 'day' => null, 'item_id' => 73],
        ], $reminders);
    }

    public function test_a_lead_of_zero_omits_the_lead_reminder(): void
    {
        $reminders = native_notification_reminders(
            [self::entry(42, 'Catan', '2030-01-10', 'upcoming')],
            self::prefs(0, true)
        );

        $this->assertSame([422, 423], array_column($reminders, 'id'));
    }

    public function test_overdue_reminders_off_omits_both_the_dated_overdue_and_the_catch_up(): void
    {
        $reminders = native_notification_reminders(
            [
                self::entry(42, 'Catan', '2030-01-10', 'upcoming'),
                self::entry(73, 'Azul', '2029-12-01', 'overdue'),
            ],
            self::prefs(3, false)
        );

        $this->assertSame([421, 422, 731, 732], array_column($reminders, 'id'));
    }

    public static function missingFields(): array
    {
        return [
            'no id' => [self::entry(null, 'Catan', '2030-01-10')],
            'no title' => [self::entry(42, null, '2030-01-10')],
            'blank title' => [self::entry(42, '', '2030-01-10')],
            'no use-by date' => [self::entry(42, 'Catan', null)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingFields')]
    public function test_an_item_with_no_id_title_or_use_by_date_produces_none(array $entry): void
    {
        $this->assertSame([], native_notification_reminders([$entry], self::prefs()));
    }

    public function test_a_daylight_saving_change_cannot_move_the_day(): void
    {
        // Spring forward in America/New_York is 2024-03-10: a use-by date of
        // 2024-03-11 with a lead of 1 is dated 2024-03-10, and a use-by date
        // of 2024-03-10 has its overdue reminder on 2024-03-11.
        $lead = native_notification_reminders(
            [self::entry(42, 'Catan', '2024-03-11', 'upcoming')],
            self::prefs(1, false)
        );
        $this->assertSame('2024-03-10', $lead[0]['day']);

        $overdue = native_notification_reminders(
            [self::entry(42, 'Catan', '2024-03-10', 'upcoming')],
            self::prefs(0, true)
        );
        $this->assertSame('2024-03-11', $overdue[1]['day']);
    }

    public function test_reminders_stay_in_queue_order_across_items(): void
    {
        $reminders = native_notification_reminders(
            [
                self::entry(42, 'Catan', '2030-01-10', 'upcoming'),
                self::entry(73, 'Azul', '2029-12-01', 'overdue'),
            ],
            self::prefs(0, true)
        );

        $this->assertSame([422, 423, 732, 733, 733], array_column($reminders, 'id'));
        $this->assertSame([42, 42, 73, 73, 73], array_column($reminders, 'item_id'));
    }
}

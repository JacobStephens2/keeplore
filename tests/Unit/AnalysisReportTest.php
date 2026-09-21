<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';
require_once PROJECT_PATH . '/private/analysis.php';

/**
 * Seam: analysis_report() - every figure the /analysis page shows, computed
 * from one user's plain rows and an explicit "today". No database.
 */
class AnalysisReportTest extends TestCase
{
    private function item(int $id, array $changes = []): array
    {
        return array_replace([
            'id' => $id,
            'Title' => 'Item ' . $id,
            'type' => 'table-game',
            'is_kept' => 1,
            'to_get_rid_of' => 0,
            'Acq' => '2020-01-01',
        ], $changes);
    }

    private function useOf(int $item_id, string $date, ?int $id = null): array
    {
        static $next = 1000;
        return ['id' => $id ?? $next++, 'artifact_id' => $item_id, 'use_date' => $date];
    }

    private function report(array $data, string $today = '2026-09-21'): array
    {
        return analysis_report(array_replace([
            'uses' => [],
            'items' => [],
            'people' => [],
            'participations' => [],
        ], $data), $today);
    }

    public function test_headline_counts_people_items_and_uses(): void
    {
        $report = $this->report([
            'items' => [
                $this->item(1),
                $this->item(2, ['is_kept' => 0]),
                $this->item(3, ['to_get_rid_of' => 1]),
            ],
            'people' => [
                ['id' => 7, 'FirstName' => 'Ada', 'LastName' => 'Lovelace'],
                ['id' => 8, 'FirstName' => 'Alan', 'LastName' => 'Turing'],
                // The player standing for the user is not a tracked person.
                ['id' => 9, 'FirstName' => 'Me', 'LastName' => '', 'represents_user_id' => 1],
            ],
            'uses' => [
                $this->useOf(1, '2026-09-01'),
                $this->useOf(1, '2026-09-02'),
                $this->useOf(2, '2025-01-05'),
            ],
        ]);

        $this->assertSame(2, $report['totals']['people']);
        $this->assertSame(3, $report['totals']['items']);
        $this->assertSame(2, $report['totals']['kept_items']);
        $this->assertSame(3, $report['totals']['uses']);
    }

    public function test_pace_compares_each_window_with_the_one_before_it(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-21'), // today: in the last 30 days
                $this->useOf(1, '2026-08-23'), // 29 days ago: still in
                $this->useOf(1, '2026-08-22'), // 30 days ago: prior window
                $this->useOf(1, '2026-07-24'), // 59 days ago: prior window
                $this->useOf(1, '2026-07-23'), // 60 days ago: neither
                $this->useOf(1, '2025-09-21'), // last year, up to this date
                $this->useOf(1, '2025-09-22'), // last year, after this date
                $this->useOf(1, '2026-12-25'), // future-dated: no window
            ],
        ]);

        $this->assertSame(2, $report['pace']['last_30']);
        $this->assertSame(2, $report['pace']['prior_30']);
        $this->assertSame(5, $report['pace']['this_year']);
        $this->assertSame(1, $report['pace']['last_year_to_date']);
    }

    public function test_tracking_span_and_weekly_average_start_at_the_first_use(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-08'),
                $this->useOf(1, '2026-09-10'),
                $this->useOf(1, '2026-09-10'),
                $this->useOf(1, '2026-09-21'),
            ],
        ]);

        $this->assertSame('2026-09-08', $report['pace']['first_use']);
        $this->assertSame(14, $report['pace']['days_tracking']);
        $this->assertSame(2.0, $report['pace']['avg_per_week']);
    }

    public function test_monthly_trend_lines_up_the_last_twelve_months_with_the_year_before(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-03'),
                $this->useOf(1, '2026-09-04'),
                $this->useOf(1, '2025-10-31'),
                $this->useOf(1, '2025-09-15'), // same month, year before
                $this->useOf(1, '2024-10-01'), // first month of the year before
                $this->useOf(1, '2024-09-30'), // too old for either line
                $this->useOf(1, '2026-09-30'), // future-dated: not yet a use
            ],
        ]);

        $monthly = $report['monthly'];
        $this->assertCount(12, $monthly['labels']);
        $this->assertSame('Oct 2025', $monthly['labels'][0]);
        $this->assertSame('Sep 2026', $monthly['labels'][11]);
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2], $monthly['current']);
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1], $monthly['previous']);
    }

    public function test_calendar_covers_the_last_53_weeks_in_whole_weeks_ending_today(): void
    {
        // 2026-09-21 is a Monday.
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-21'),
                $this->useOf(1, '2026-09-21'),
                $this->useOf(1, '2026-09-20'),
            ],
        ]);

        $days = $report['calendar']['days'];
        $this->assertSame('2025-09-21', $days[0]['date']); // a Sunday
        // level: 0 for no uses, then 1-4 scaled against the busiest day shown.
        $this->assertSame(['date' => '2026-09-21', 'count' => 2, 'level' => 4], end($days));
        $this->assertSame(['date' => '2026-09-20', 'count' => 1, 'level' => 2], $days[count($days) - 2]);
        $this->assertSame(0, $days[0]['level']);
        $this->assertCount(52 * 7 + 2, $days);
    }

    public function test_weekday_rhythm_runs_sunday_through_saturday(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-20'), // Sunday
                $this->useOf(1, '2026-09-21'), // Monday
                $this->useOf(1, '2026-09-14'), // Monday
                $this->useOf(1, '2026-09-19'), // Saturday
                $this->useOf(1, '2026-09-23'), // future-dated: not yet a use
            ],
        ]);

        $this->assertSame(
            ['Sun' => 1, 'Mon' => 2, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 1],
            array_column($report['weekdays'], 'count', 'label')
        );
    }

    public function test_streaks_survive_the_daylight_saving_change(): void
    {
        // US clocks fall back on 2025-11-02; the run must not break there.
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2025-11-01'),
                $this->useOf(1, '2025-11-02'),
                $this->useOf(1, '2025-11-03'),
                $this->useOf(1, '2025-11-03'),
                $this->useOf(1, '2026-09-20'),
                $this->useOf(1, '2026-09-21'),
            ],
        ]);

        $this->assertSame(
            ['days' => 3, 'start' => '2025-11-01', 'end' => '2025-11-03'],
            $report['records']['longest_streak']
        );
        $this->assertSame(2, $report['records']['current_streak']);
    }

    public function test_current_streak_still_counts_when_today_has_no_use_yet(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-19'),
                $this->useOf(1, '2026-09-20'),
            ],
        ]);
        $this->assertSame(2, $report['records']['current_streak']);

        $lapsed = $this->report([
            'items' => [$this->item(1)],
            'uses' => [$this->useOf(1, '2026-09-19')],
        ]);
        $this->assertSame(0, $lapsed['records']['current_streak']);
    }

    public function test_records_name_the_busiest_day_and_month(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-03-05'),
                $this->useOf(1, '2026-03-05'),
                $this->useOf(1, '2026-03-06'),
                $this->useOf(1, '2026-04-01'),
                $this->useOf(1, '2026-04-01'),
            ],
        ]);

        // Ties go to the more recent day.
        $this->assertSame(['date' => '2026-04-01', 'count' => 2], $report['records']['busiest_day']);
        $this->assertSame(['month' => 'Mar 2026', 'count' => 3], $report['records']['busiest_month']);
    }

    public function test_top_items_rank_by_uses_then_title_for_the_window_and_all_time(): void
    {
        $report = $this->report([
            'items' => [
                $this->item(1, ['Title' => 'Azul']),
                $this->item(2, ['Title' => 'Brass', 'type' => 'book']),
                $this->item(3, ['Title' => 'Catan']),
            ],
            'uses' => [
                $this->useOf(1, '2026-09-01'),
                $this->useOf(2, '2026-09-02'),
                $this->useOf(3, '2026-06-24'), // 89 days ago: inside 90 days
                $this->useOf(3, '2026-06-23'), // 90 days ago: outside
                $this->useOf(3, '2020-01-01'),
                $this->useOf(99, '2026-09-03'), // item since deleted
            ],
        ]);

        $this->assertSame(
            [
                ['id' => 1, 'title' => 'Azul', 'type' => 'table-game', 'count' => 1, 'last_used' => '2026-09-01'],
                ['id' => 2, 'title' => 'Brass', 'type' => 'book', 'count' => 1, 'last_used' => '2026-09-02'],
                ['id' => 3, 'title' => 'Catan', 'type' => 'table-game', 'count' => 1, 'last_used' => '2026-06-24'],
            ],
            $report['top_recent']
        );
        $this->assertSame(
            ['id' => 3, 'title' => 'Catan', 'type' => 'table-game', 'count' => 3, 'last_used' => '2026-06-24'],
            $report['top_all_time'][0]
        );
        $this->assertSame(
            [['label' => 'table-game', 'count' => 2], ['label' => 'book', 'count' => 1]],
            $report['types']
        );
    }

    public function test_top_lists_stop_at_ten(): void
    {
        $items = [];
        $uses = [];
        for ($id = 1; $id <= 12; $id++) {
            $items[] = $this->item($id);
            $uses[] = $this->useOf($id, '2026-09-01');
        }
        $report = $this->report(['items' => $items, 'uses' => $uses]);

        $this->assertCount(10, $report['top_recent']);
        $this->assertCount(10, $report['top_all_time']);
    }

    public function test_kept_items_sort_into_recency_buckets_by_last_use(): void
    {
        $report = $this->report([
            'items' => [
                $this->item(1), // used today
                $this->item(2), // 29 days ago
                $this->item(3), // 30 days ago
                $this->item(4), // 364 days ago
                $this->item(5), // 365 days ago
                $this->item(6), // never
                $this->item(7, ['is_kept' => 0]), // not kept: left out
            ],
            'uses' => [
                $this->useOf(1, '2026-09-21'),
                $this->useOf(1, '2019-01-01'),
                $this->useOf(2, '2026-08-23'),
                $this->useOf(3, '2026-08-22'),
                $this->useOf(4, '2025-09-22'),
                $this->useOf(5, '2025-09-21'),
                $this->useOf(7, '2026-09-21'),
            ],
        ]);

        $this->assertSame(
            [
                ['label' => 'Last 30 days', 'count' => 2],
                ['label' => '31-90 days', 'count' => 1],
                ['label' => '91-365 days', 'count' => 1],
                ['label' => 'Over a year', 'count' => 1],
                ['label' => 'Never used', 'count' => 1],
            ],
            $report['recency']['buckets']
        );
        $this->assertSame(4, $report['recency']['used_past_year']);
        $this->assertSame(6, $report['recency']['kept_items']);
        $this->assertSame(67, $report['recency']['used_past_year_percent']);
    }

    public function test_neglected_lists_kept_items_idle_longest_and_skips_those_already_leaving(): void
    {
        $report = $this->report([
            'items' => [
                $this->item(1, ['Title' => 'Fresh']),
                $this->item(2, ['Title' => 'Dusty']),
                $this->item(3, ['Title' => 'Unopened', 'Acq' => '2024-09-21']),
                $this->item(4, ['Title' => 'Leaving', 'to_get_rid_of' => 1]),
                $this->item(5, ['Title' => 'Gone', 'is_kept' => 0]),
                $this->item(6, ['Title' => 'Undated', 'Acq' => null]),
            ],
            'uses' => [
                $this->useOf(1, '2026-09-20'),
                $this->useOf(2, '2025-09-21'),
            ],
        ]);

        $this->assertSame(
            [
                ['id' => 3, 'title' => 'Unopened', 'last_used' => null, 'since' => '2024-09-21', 'days_idle' => 730],
                ['id' => 2, 'title' => 'Dusty', 'last_used' => '2025-09-21', 'since' => '2025-09-21', 'days_idle' => 365],
                ['id' => 1, 'title' => 'Fresh', 'last_used' => '2026-09-20', 'since' => '2026-09-20', 'days_idle' => 1],
            ],
            $report['neglected']
        );
    }

    public function test_concentration_is_the_share_of_uses_on_the_ten_most_used_items(): void
    {
        $items = [];
        $uses = [];
        for ($id = 1; $id <= 12; $id++) {
            $items[] = $this->item($id);
            $uses[] = $this->useOf($id, '2026-09-01');
        }
        for ($extra = 0; $extra < 8; $extra++) {
            $uses[] = $this->useOf(1, '2026-08-01');
        }
        // 20 uses; the top ten items hold 9 + 9 * 1 = 18 of them.
        $report = $this->report(['items' => $items, 'uses' => $uses]);

        $this->assertSame(90, $report['records']['top_ten_share']);
    }

    public function test_company_ranks_the_people_who_shared_the_most_uses(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'people' => [
                ['id' => 7, 'FirstName' => 'Ada', 'LastName' => 'Lovelace', 'represents_user_id' => null],
                ['id' => 8, 'FirstName' => 'Alan', 'LastName' => '', 'represents_user_id' => null],
                ['id' => 9, 'FirstName' => 'Me', 'LastName' => 'Myself', 'represents_user_id' => 1],
                ['id' => 10, 'FirstName' => 'Never', 'LastName' => 'Joined', 'represents_user_id' => null],
            ],
            'uses' => [
                $this->useOf(1, '2026-09-01', 501),
                $this->useOf(1, '2026-09-05', 502),
                $this->useOf(1, '2026-09-09', 503),
            ],
            'participations' => [
                ['use_id' => 501, 'player_id' => 7],
                ['use_id' => 501, 'player_id' => 7], // duplicate junction row
                ['use_id' => 502, 'player_id' => 7],
                ['use_id' => 502, 'player_id' => 8],
                ['use_id' => 501, 'player_id' => 9], // the user: not company
                ['use_id' => 502, 'player_id' => 9],
                ['use_id' => 503, 'player_id' => 9],
                ['use_id' => 999, 'player_id' => 8], // use since deleted
            ],
        ]);

        $this->assertSame(
            [
                ['id' => 7, 'name' => 'Ada Lovelace', 'count' => 2, 'last_shared' => '2026-09-05'],
                ['id' => 8, 'name' => 'Alan', 'count' => 1, 'last_shared' => '2026-09-05'],
            ],
            $report['company']['people']
        );
        // Uses 501 and 502 had company; 503 was the user alone.
        $this->assertSame(2, $report['company']['people_count']);
        $this->assertSame(2, $report['company']['shared_uses']);
        $this->assertSame(1, $report['company']['solo_uses']);
    }

    public function test_settings_rank_by_uses_and_name_the_items_most_used_in_each(): void
    {
        $at = fn(int $item_id, string $setting) =>
            $this->useOf($item_id, '2026-09-01') + ['note' => $setting];

        $report = $this->report([
            'items' => [
                $this->item(1, ['Title' => 'Azul']),
                $this->item(2, ['Title' => 'Brass']),
                $this->item(3, ['Title' => 'Catan']),
            ],
            'uses' => [
                $at(1, 'Home'),
                $at(1, 'home '), // same setting, typed differently
                $at(2, 'Home'),
                $at(3, 'Home'),
                $at(3, 'Home'),
                $at(3, 'Home'),
                $at(2, 'Cafe'),
                $at(99, 'Cafe'), // item since deleted: counts for the setting only
                $at(1, ''),      // no setting recorded
            ],
        ]);

        $this->assertSame(
            [
                [
                    'setting' => 'Home',
                    'count' => 6,
                    'items' => [
                        ['id' => 3, 'title' => 'Catan', 'count' => 3],
                        ['id' => 1, 'title' => 'Azul', 'count' => 2],
                        ['id' => 2, 'title' => 'Brass', 'count' => 1],
                    ],
                ],
                [
                    'setting' => 'Cafe',
                    'count' => 2,
                    'items' => [['id' => 2, 'title' => 'Brass', 'count' => 1]],
                ],
            ],
            $report['settings']
        );
    }

    public function test_settings_keep_the_top_six_with_five_items_each(): void
    {
        $items = [];
        $uses = [];
        for ($id = 1; $id <= 7; $id++) {
            $items[] = $this->item($id);
            $uses[] = $this->useOf($id, '2026-09-01') + ['note' => 'Home'];
            $uses[] = $this->useOf($id, '2026-09-02') + ['note' => 'Setting ' . $id];
        }
        $report = $this->report(['items' => $items, 'uses' => $uses]);

        $this->assertCount(6, $report['settings']);
        $this->assertSame('Home', $report['settings'][0]['setting']);
        $this->assertCount(5, $report['settings'][0]['items']);
    }

    public function test_uses_without_a_real_date_count_as_uses_but_stay_off_the_timeline(): void
    {
        $report = $this->report([
            'items' => [$this->item(1)],
            'uses' => [
                $this->useOf(1, '2026-09-20'),
                ['id' => 1, 'artifact_id' => 1, 'use_date' => null],
                ['id' => 2, 'artifact_id' => 1, 'use_date' => '0000-00-00'],
            ],
        ]);

        $this->assertSame(3, $report['totals']['uses']);
        $this->assertSame('2026-09-20', $report['pace']['first_use']);
        $this->assertSame(3, $report['top_all_time'][0]['count']);
    }

    public function test_an_empty_history_reports_zeros_not_errors(): void
    {
        $report = $this->report([]);

        $this->assertSame(0, $report['totals']['uses']);
        $this->assertNull($report['pace']['first_use']);
        $this->assertSame(0, $report['pace']['days_tracking']);
        $this->assertSame(0.0, $report['pace']['avg_per_week']);
    }
}

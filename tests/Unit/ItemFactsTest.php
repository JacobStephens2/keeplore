<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_facts.php';

/**
 * Seams, Item facts' interface: pure functions over an item row that read
 * its sweet spot, minimum age, player range and play time whichever way its
 * columns are spelled.
 */
class ItemFactsTest extends TestCase
{
    /** The ids of the rows the predicate keeps. */
    private function kept(array $rows, callable $keep): array
    {
        return array_values(array_column(array_filter($rows, $keep), 'id'));
    }

    /**
     * @dataProvider sweetSpotSpellings
     */
    public function test_sweet_spot_counts_read_every_stored_spelling(string $ss, array $counts): void
    {
        $this->assertSame($counts, item_sweet_spot_counts(['SS' => $ss]));
        $this->assertSame($counts, item_sweet_spot_counts(['ss' => $ss]));
    }

    public static function sweetSpotSpellings(): array
    {
        return [
            'blank' => ['', []],
            'bgg zero padded' => ['01', [1]],
            'unpadded' => ['1', [1]],
            'padded list' => ['03,04', [3, 4]],
            'spaced list' => ['01, 2, 3, 4', [1, 2, 3, 4]],
            'range' => ['06-8', [6, 7, 8]],
            'spaced range' => ['3 - 5', [3, 4, 5]],
            'runaway range capped' => ['98-2000000000', [98, 99]],
            'range and count' => ['03-4, 6', [3, 4, 6]],
            'trailing tab' => ["02,3\t", [2, 3]],
            'out of order' => ['10, 5, 1', [1, 5, 10]],
            'wide range' => ['03,10-12', [3, 10, 11, 12]],
        ];
    }

    public function test_no_sweet_spot_column_has_no_counts(): void
    {
        $this->assertSame([], item_sweet_spot_counts(['Title' => 'Hat']));
    }

    public function test_players_label_is_the_range_with_its_sweet_spot(): void
    {
        $this->assertSame('2–4 (best 3)', item_players_label(['MnP' => 2, 'MxP' => 4, 'SS' => '03']));
        $this->assertSame('3–6 (best 3–5)', item_players_label(['MnP' => 3, 'MxP' => 6, 'SS' => '03-5']));
        $this->assertSame('1–8 (best 3, 4, 6)', item_players_label(['MnP' => 1, 'MxP' => 8, 'SS' => '03,04,06']));
        $this->assertSame('2–4', item_players_label(['MnP' => 2, 'MxP' => 4, 'SS' => '']));
        $this->assertSame('2 (best 2)', item_players_label(['MnP' => 2, 'MxP' => 2, 'SS' => '02']));
        $this->assertSame('best 3', item_players_label(['SS' => '03']));
        $this->assertSame('', item_players_label([]));
    }

    public function test_players_label_reads_either_column_spelling(): void
    {
        $this->assertSame('2–4 (best 2)', item_players_label(['mnp' => 2, 'mxp' => 4, 'ss' => '02']));
    }

    public function test_plays_best_at_keeps_only_items_whose_sweet_spot_holds_the_count(): void
    {
        $rows = [
            ['id' => 1, 'ss' => '03'],
            ['id' => 2, 'ss' => '06-8'],
            ['id' => 3, 'ss' => '13'],
            ['id' => 4, 'ss' => ''],
            ['id' => 5, 'ss' => '02, 3, 4'],
        ];

        $this->assertSame([1, 5], $this->kept($rows, fn ($row) => item_plays_best_at($row, 3)));
        $this->assertSame([2], $this->kept($rows, fn ($row) => item_plays_best_at($row, 7)));
    }

    public function test_suits_age_keeps_items_whose_minimum_age_is_known_and_at_most_the_age(): void
    {
        $rows = [
            ['id' => 1, 'Age' => 8],
            ['id' => 2, 'Age' => 7],
            ['id' => 3, 'Age' => 2],
            ['id' => 4, 'Age' => 0],
            ['id' => 5, 'Age' => null],
            ['id' => 6],
            ['id' => 7, 'age' => '3'],
        ];

        $this->assertSame([2, 3, 7], $this->kept($rows, fn ($row) => item_suits_age($row, 7)));
        $this->assertSame([3], $this->kept($rows, fn ($row) => item_suits_age($row, 2)));
    }

    public function test_suits_age_can_include_items_with_no_recorded_age(): void
    {
        $rows = [
            ['id' => 1, 'Age' => 8],
            ['id' => 2, 'Age' => 2],
            ['id' => 3, 'Age' => 0],
            ['id' => 4, 'Age' => null],
            ['id' => 5],
        ];

        $this->assertSame([2, 3, 4, 5], $this->kept($rows, fn ($row) => item_suits_age($row, 2, true)));
        $this->assertSame([2], $this->kept($rows, fn ($row) => item_suits_age($row, 2, false)));
    }

    public function test_min_age_is_the_recorded_age_or_null(): void
    {
        $this->assertSame(8, item_min_age(['Age' => 8]));
        $this->assertSame(3, item_min_age(['age' => '3']));
        $this->assertNull(item_min_age(['Age' => 0]));
        $this->assertNull(item_min_age([]));
    }

    public function test_copy_text_is_name_player_range_with_best_counts_and_minimum_age(): void
    {
        $text = fn (array $fields) => item_copy_text($fields + ['Title' => 'Azul']);

        $this->assertSame('Azul, 2–4 (2), 8 yrs', $text(['mnp' => 2, 'mxp' => 4, 'ss' => '02', 'Age' => 8]));
        $this->assertSame('Azul, 3–8 (5–7), 14 yrs', $text(['mnp' => 3, 'mxp' => 8, 'ss' => '05,06,07', 'Age' => 14]));
        $this->assertSame('Azul, 2–5 (3, 4), 10 yrs', $text(['mnp' => 2, 'mxp' => 5, 'ss' => '3, 4', 'Age' => 10]));
        $this->assertSame('Azul, 1–4, 8 yrs', $text(['mnp' => 1, 'mxp' => 4, 'ss' => '', 'Age' => 8]));
        $this->assertSame('Azul, 2–4 (2)', $text(['mnp' => 2, 'mxp' => 4, 'ss' => '02', 'Age' => 0]));
        $this->assertSame('Azul, 8 yrs', $text(['Age' => 8]));
        $this->assertSame('Azul', $text([]));
    }

    public function test_players_label_still_reads_best_after_extracting_the_best_counts(): void
    {
        $this->assertSame('3, 4', item_best_counts_label(['SS' => '03,04']));
        $this->assertSame('5–7', item_best_counts_label(['SS' => '05,06,07']));
        $this->assertSame('', item_best_counts_label(['SS' => '']));
        $this->assertSame('2–5 (best 3, 4)', item_players_label(['MnP' => 2, 'MxP' => 5, 'SS' => '3, 4']));
        $this->assertSame('best 3', item_players_label(['MnP' => 0, 'MxP' => 0, 'SS' => '3']));
    }

    public function test_copy_text_keeps_the_best_count_when_no_range_is_recorded(): void
    {
        $this->assertSame('Azul, best 3, 8 yrs', item_copy_text(['Title' => 'Azul', 'ss' => '03', 'Age' => 8]));
    }

    public function test_play_time_is_the_time_range_in_minutes(): void
    {
        $this->assertSame('30–60 min', item_play_time(['MnT' => 30, 'MxT' => 60]));
        $this->assertSame('45 min', item_play_time(['mnt' => 45]));
        $this->assertSame('', item_play_time([]));
    }

    public function test_play_facts_read_players_best_count_time_and_age_from_an_item_record(): void
    {
        // Edit Item reads the games row as stored: MnP, MxP, SS, Age.
        $this->assertSame('2–4 players, best 3 · Age 8+', item_play_facts(['MnP' => 2, 'MxP' => 4, 'SS' => '03', 'Age' => 8]));
        $this->assertSame('1–5 players, best 3, 4 · Age 10+', item_play_facts(['MnP' => 1, 'MxP' => 5, 'SS' => '03,04', 'Age' => 10]));
        $this->assertSame('2 players · Age 8+', item_play_facts(['MnP' => 2, 'MxP' => 2, 'SS' => '', 'Age' => 8]));
        $this->assertSame('1 player', item_play_facts(['MnP' => 1, 'MxP' => 1, 'SS' => '', 'Age' => 0]));
        $this->assertSame('Best at 3 · Age 6+', item_play_facts(['SS' => '3', 'Age' => 6]));
        $this->assertSame('', item_play_facts(['Title' => 'Hat']));
        $this->assertSame('2–7 players, best 4, 5 · 15–20 min · Age 6+',
            item_play_facts(['MnP' => 2, 'MxP' => 7, 'SS' => '4,5', 'MnT' => 15, 'MxT' => 20, 'Age' => 6]));
        $this->assertSame('30 min', item_play_facts(['mnt' => 30, 'mxt' => 30]));
    }

    public function test_play_facts_can_leave_out_the_play_time(): void
    {
        $this->assertSame('2–4 players, best 3 · Age 8+',
            item_play_facts(['MnP' => 2, 'MxP' => 4, 'SS' => '03', 'MnT' => 30, 'MxT' => 60, 'Age' => 8], false));
    }

    public function test_candidate_is_a_non_blank_candidate_that_is_not_zero(): void
    {
        $this->assertTrue(item_is_candidate(['Candidate' => '03: Ann, Ben at home']));
        $this->assertTrue(item_is_candidate(['candidate' => '1']));
        $this->assertFalse(item_is_candidate(['Candidate' => '']));
        $this->assertFalse(item_is_candidate(['Candidate' => '   ']));
        $this->assertFalse(item_is_candidate(['Candidate' => '0']));
        $this->assertFalse(item_is_candidate(['Candidate' => ' 0 ']));
        $this->assertFalse(item_is_candidate(['Candidate' => null]));
        $this->assertFalse(item_is_candidate([]));
    }

    public function test_average_play_time_is_half_the_time_range_rounded_up(): void
    {
        $this->assertSame(60, item_average_play_time(['MnT' => 45, 'MxT' => 75]));
        $this->assertSame(21, item_average_play_time(['mnt' => 20, 'mxt' => 21]));
        // An unrecorded end counts as 0.
        $this->assertSame(30, item_average_play_time(['mxt' => '60']));
        $this->assertSame(23, item_average_play_time(['MnT' => 45, 'MxT' => null]));
        $this->assertSame(0, item_average_play_time([]));
    }
}

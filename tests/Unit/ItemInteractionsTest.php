<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_interactions.php';

/**
 * Seams:
 * - item_interactions(): the rows Item Interactions shows, from the uses and
 *   items it is given, plus the input use count and the two combination
 *   counts. No database.
 * - ui/uses/interactions.php source: reads the owner's uses through Uses,
 *   item attributes through the Items listing, shows a minimum date Uses
 *   rejects, and holds no query of its own. The group-setting text, the
 *   hides, the combination counts and the candidate-spot match are not
 *   built on the page.
 */
class ItemInteractionsTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/uses/interactions.php');
    }

    /** Ann Dee (id 1) and Ben Cole (id 2), the worked example's people. */
    private function annAndBen(): array
    {
        return [
            ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Dee'],
            ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Cole'],
        ];
    }

    private function useOf(array $overrides = []): array
    {
        return array_replace([
            'id' => 11,
            'item_id' => 7,
            'item_title' => 'Catan',
            'item_type' => 'Game',
            'use_date' => '2024-01-02',
            'setting' => 'home',
            'people' => $this->annAndBen(),
        ], $overrides);
    }

    public function test_the_example_group_setting_names_ann_and_ben_at_home_for_catan(): void
    {
        $result = item_interactions([
            $this->useOf([
                'id' => 11,
                'use_date' => '2024-01-02 15:00:00',
                'people' => [
                    ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Cole'],
                    ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Dee'],
                ],
            ]),
        ], [
            ['id' => 7, 'Title' => 'Catan', 'SS' => '03,04', 'Candidate' => '03: Ann, Ben at home'],
        ], false, false);

        $this->assertSame([
            'id' => 11,
            'use_date' => '2024-01-02',
            'item_id' => 7,
            'item_title' => 'Catan',
            'item_type' => 'Game',
            'setting' => 'home',
            'person_count' => 2,
            'group_setting' => '02: Ann Dee, Ben Cole at home (Catan on 2024-01-02)',
            'candidate' => true,
            'sweet_spot' => '03,04',
            'candidate_spot' => null,
        ], $result['rows'][0]);
        $this->assertSame(1, $result['use_count']);
    }

    public function test_a_count_of_ten_has_no_leading_zero(): void
    {
        $people = [];
        for ($id = 1; $id <= 10; $id++) {
            $people[] = ['id' => $id, 'first_name' => 'P' . $id, 'last_name' => 'L' . $id];
        }

        $result = item_interactions([
            $this->useOf(['people' => $people]),
        ], [], false, false);

        $this->assertSame(
            '10: P1 L1, P2 L2, P3 L3, P4 L4, P5 L5, P6 L6, P7 L7, P8 L8, P9 L9, P10 L10 at home (Catan on 2024-01-02)',
            $result['rows'][0]['group_setting']
        );
    }

    public function test_an_online_setting_has_no_at(): void
    {
        $result = item_interactions([
            $this->useOf(['setting' => 'online']),
        ], [], false, false);

        $this->assertSame(
            '02: Ann Dee, Ben Cole online (Catan on 2024-01-02)',
            $result['rows'][0]['group_setting']
        );
    }

    public function test_duplicate_person_ids_keep_the_last_name_and_the_original_row_count(): void
    {
        $result = item_interactions([
            $this->useOf([
                'people' => [
                    ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Cole'],
                    ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Dee'],
                    ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Other'],
                ],
            ]),
        ], [], false, false);

        $row = $result['rows'][0];
        $this->assertSame(3, $row['person_count']);
        $this->assertSame(
            '03: Ann Other, Ben Cole,  at home (Catan on 2024-01-02)',
            $row['group_setting']
        );
    }

    public function test_hiding_online_leaves_that_use_out_of_the_rows_and_both_counts(): void
    {
        $uses = [
            $this->useOf(['id' => 1, 'setting' => 'home']),
            $this->useOf(['id' => 2, 'setting' => 'online', 'item_title' => 'Azul']),
        ];

        $hidden = item_interactions($uses, [], true, false);
        $this->assertSame([1], array_column($hidden['rows'], 'id'));
        $this->assertSame(2, $hidden['use_count']);
        $this->assertSame(1, $hidden['group_and_setting_count']);
        $this->assertSame(1, $hidden['group_setting_game_count']);

        $shown = item_interactions($uses, [], false, false);
        $this->assertSame([1, 2], array_column($shown['rows'], 'id'));
        $this->assertSame(2, $shown['group_and_setting_count']);
        $this->assertSame(2, $shown['group_setting_game_count']);
    }

    public function test_hiding_duplicate_group_settings_keeps_the_first_and_drops_the_later(): void
    {
        $uses = [
            $this->useOf(['id' => 1, 'use_date' => '2024-01-02']),
            $this->useOf(['id' => 2, 'use_date' => '2024-02-02']),
        ];

        $hidden = item_interactions($uses, [], false, true);
        $this->assertSame([1], array_column($hidden['rows'], 'id'));
        $this->assertSame(
            '02: Ann Dee, Ben Cole at home (Catan on 2024-01-02)',
            $hidden['rows'][0]['group_setting']
        );
        $this->assertSame(2, $hidden['use_count']);
        $this->assertSame(1, $hidden['group_and_setting_count']);
        $this->assertSame(1, $hidden['group_setting_game_count']);

        $shown = item_interactions($uses, [], false, false);
        $this->assertSame([1, 2], array_column($shown['rows'], 'id'));
        $this->assertSame(1, $shown['group_and_setting_count']);
        $this->assertSame(1, $shown['group_setting_game_count']);
    }

    public function test_combination_keys_are_case_sensitive(): void
    {
        $uses = [
            $this->useOf(['id' => 1, 'setting' => 'Home']),
            $this->useOf(['id' => 2, 'setting' => 'home']),
        ];

        $result = item_interactions($uses, [], false, true);

        $this->assertSame([1, 2], array_column($result['rows'], 'id'));
        $this->assertSame(2, $result['group_and_setting_count']);
        $this->assertSame(2, $result['group_setting_game_count']);
    }

    public function test_the_candidate_spot_matches_a_note_that_starts_with_the_key_ignoring_case(): void
    {
        $result = item_interactions([
            $this->useOf(),
        ], [
            ['id' => 7, 'Title' => 'Catan', 'SS' => '4', 'Candidate' => '0'],
            ['id' => 9, 'Title' => 'Azul', 'SS' => '2', 'Candidate' => '02: ANN DEE, BEN COLE AT HOME (CATAN on 2024-01-02)'],
        ], false, false);

        $row = $result['rows'][0];
        $this->assertSame('Azul', $row['candidate_spot']);
        $this->assertFalse($row['candidate']);
        $this->assertSame('4', $row['sweet_spot']);
    }

    public function test_a_title_containing_an_ampersand_or_less_than_matches_a_note_with_those_characters(): void
    {
        $result = item_interactions([
            $this->useOf(['id' => 1, 'item_title' => 'Cats & Dogs']),
            $this->useOf(['id' => 2, 'item_title' => 'A < B']),
        ], [
            ['id' => 3, 'Title' => 'Amp', 'Candidate' => '02: Ann Dee, Ben Cole at home (Cats & Dogs'],
            ['id' => 4, 'Title' => 'Lt', 'Candidate' => '02: Ann Dee, Ben Cole at home (A < B'],
        ], false, false);

        $this->assertSame('Amp', $result['rows'][0]['candidate_spot']);
        $this->assertSame('Lt', $result['rows'][1]['candidate_spot']);
        $this->assertStringContainsString('(Cats & Dogs on ', $result['rows'][0]['group_setting']);
        $this->assertStringContainsString('(A < B on ', $result['rows'][1]['group_setting']);
    }

    public function test_the_first_matching_item_in_the_given_order_is_the_candidate_spot(): void
    {
        $result = item_interactions([
            $this->useOf(),
        ], [
            ['id' => 1, 'Title' => 'No', 'Candidate' => 'not this group'],
            ['id' => 2, 'Title' => 'First', 'Candidate' => '02: Ann Dee, Ben Cole at home (Catan'],
            ['id' => 3, 'Title' => 'Second', 'Candidate' => '02: ann dee, ben cole at home (catan and more'],
        ], false, false);

        $this->assertSame('First', $result['rows'][0]['candidate_spot']);
    }

    public function test_an_empty_item_list_has_no_candidate_spot_mark_or_sweet_spot(): void
    {
        $result = item_interactions([$this->useOf()], [], false, false);

        $row = $result['rows'][0];
        $this->assertNull($row['candidate_spot']);
        $this->assertFalse($row['candidate']);
        $this->assertSame('', $row['sweet_spot']);
    }

    public function test_both_combination_counts_skip_hidden_online_uses_and_keep_distinct_keys(): void
    {
        $uses = [
            $this->useOf(['id' => 1, 'item_title' => 'Catan', 'setting' => 'home', 'use_date' => '2024-01-02']),
            $this->useOf(['id' => 2, 'item_title' => 'Ticket to Ride', 'setting' => 'home', 'use_date' => '2024-01-03']),
            $this->useOf(['id' => 3, 'item_title' => 'Catan', 'setting' => 'home', 'use_date' => '2024-02-02']),
            $this->useOf(['id' => 4, 'item_title' => 'Catan', 'setting' => 'online', 'use_date' => '2024-01-04']),
        ];

        $shown = item_interactions($uses, [], false, false);
        $this->assertSame([1, 2, 3, 4], array_column($shown['rows'], 'id'));
        $this->assertSame(4, $shown['use_count']);
        $this->assertSame(2, $shown['group_and_setting_count']);
        $this->assertSame(3, $shown['group_setting_game_count']);

        $online_hidden = item_interactions($uses, [], true, false);
        $this->assertSame([1, 2, 3], array_column($online_hidden['rows'], 'id'));
        $this->assertSame(4, $online_hidden['use_count']);
        $this->assertSame(1, $online_hidden['group_and_setting_count']);
        $this->assertSame(2, $online_hidden['group_setting_game_count']);

        $duplicates_hidden = item_interactions($uses, [], false, true);
        $this->assertSame([1, 2, 4], array_column($duplicates_hidden['rows'], 'id'));
        $this->assertSame(2, $duplicates_hidden['group_and_setting_count']);
        $this->assertSame(3, $duplicates_hidden['group_setting_game_count']);
    }

    public function test_item_interactions_reads_uses_through_the_uses_module(): void
    {
        $page = $this->page();
        $this->assertMatchesRegularExpression("/new Uses\(\\\$db, .*\)\)->all\(\[\s*'type_ids'/", $page);
        $this->assertStringContainsString("'since' =>", $page);
        $this->assertStringNotContainsString('find_uses_by_user_id', $page);
        $this->assertFalse(function_exists('find_uses_by_user_id'));
        $this->assertDoesNotMatchRegularExpression(
            '/FROM (uses|uses_players|games|players)\b/i',
            $page,
            'Item Interactions must not query uses, their people or items itself.'
        );
    }

    public function test_item_attributes_come_from_the_items_listing_loaded_once(): void
    {
        $page = $this->page();
        $this->assertSame(1, substr_count($page, '(new Items('));
        $this->assertStringContainsString('->list()', $page);
        $this->assertStringNotContainsString('singleValueQuery', $page);
        $this->assertStringNotContainsString('LIKE', $page);
    }

    public function test_a_malformed_minimum_date_shows_the_modules_message(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('catch (InvalidArgumentException $invalid)', $page);
        $this->assertStringContainsString('<?php echo h($uses_error); ?>', $page);
        $this->assertStringContainsString('value="<?php echo h((string) $minimumDate); ?>"', $page);
    }

    public function test_the_page_renders_module_rows_and_escapes_the_group_setting_once(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('item_interactions(', $page);
        $this->assertSame(1, substr_count($page, "h(\$row['group_setting'])"));
        $this->assertStringContainsString("h(\$row['candidate_spot'])", $page);
        $this->assertStringNotContainsString('mb_strtolower', $page);
        $this->assertStringContainsString("\$interactions['use_count']", $page);
        $this->assertStringContainsString("\$interactions['group_setting_game_count']", $page);
        $this->assertStringContainsString("\$interactions['group_and_setting_count']", $page);
    }
}

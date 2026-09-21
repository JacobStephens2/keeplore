<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/player_item_uses.php';

/**
 * Seams:
 * - rank_items_by_player_uses(): group a player's recorded uses by item
 *   and rank them most uses first
 * - find_player_uses(): load that player's already-scoped use rows
 * - player_use_item_cells(): Item and Type cells shared by both tables
 * - ui/users/edit.php source: loads the interactions include
 * - private/shared/user_interactions.php source: ranking without
 *   scanning the chronological interactions table
 */
class PlayerItemUsesTest extends TestCase
{
    private function useRow(int $artifactId, string $title, string $type = 'board-game'): array
    {
        return [
            'artifactID' => $artifactId,
            'Title' => $title,
            'type' => $type,
        ];
    }

    public function test_no_uses_ranks_as_an_empty_list(): void
    {
        $this->assertSame([], rank_items_by_player_uses([]));
    }

    public function test_one_use_is_one_item_with_count_one(): void
    {
        $ranked = rank_items_by_player_uses([
            $this->useRow(10, 'Catan'),
        ]);

        $this->assertSame([
            [
                'artifactID' => 10,
                'Title' => 'Catan',
                'type' => 'board-game',
                'use_count' => 1,
            ],
        ], $ranked);
    }

    public function test_repeated_uses_of_one_item_count_together(): void
    {
        $ranked = rank_items_by_player_uses([
            $this->useRow(10, 'Catan'),
            $this->useRow(10, 'Catan'),
            $this->useRow(10, 'Catan'),
        ]);

        $this->assertSame([
            [
                'artifactID' => 10,
                'Title' => 'Catan',
                'type' => 'board-game',
                'use_count' => 3,
            ],
        ], $ranked);
    }

    public function test_items_are_ranked_most_uses_first(): void
    {
        $ranked = rank_items_by_player_uses([
            $this->useRow(10, 'Catan'),
            $this->useRow(20, 'Azul'),
            $this->useRow(20, 'Azul'),
            $this->useRow(20, 'Azul'),
            $this->useRow(30, 'Wingspan'),
            $this->useRow(30, 'Wingspan'),
        ]);

        $this->assertSame(['Azul', 'Wingspan', 'Catan'], array_column($ranked, 'Title'));
        $this->assertSame([3, 2, 1], array_column($ranked, 'use_count'));
    }

    public function test_equal_counts_break_alphabetically_by_title(): void
    {
        $ranked = rank_items_by_player_uses([
            $this->useRow(10, 'Catan'),
            $this->useRow(10, 'Catan'),
            $this->useRow(40, 'The Left Hand of Darkness', 'book'),
            $this->useRow(40, 'The Left Hand of Darkness', 'book'),
            $this->useRow(20, 'Azul'),
            $this->useRow(20, 'Azul'),
        ]);

        $this->assertSame(
            ['Azul', 'Catan', 'The Left Hand of Darkness'],
            array_column($ranked, 'Title')
        );
        $this->assertSame([2, 2, 2], array_column($ranked, 'use_count'));
        $this->assertSame('book', $ranked[2]['type']);
    }

    public function test_item_cells_link_the_title_and_show_the_type(): void
    {
        $html = player_use_item_cells($this->useRow(10, 'Catan'));

        $this->assertStringContainsString('/artifacts/edit.php?id=10', $html);
        $this->assertStringContainsString('>Catan</a>', $html);
        $this->assertStringContainsString('<td>board-game</td>', $html);
    }

    public function test_item_cells_escape_title_and_type(): void
    {
        $html = player_use_item_cells($this->useRow(10, '<b>X</b>', 'a&b'));

        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html);
        $this->assertStringContainsString('a&amp;b', $html);
        $this->assertStringNotContainsString('<b>X</b>', $html);
    }

    private function editUserPage(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/users/edit.php');
    }

    private function interactionsInclude(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/private/shared/user_interactions.php');
    }

    private function findPlayerUsesFn(): string
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/private/player_item_uses.php');
        $this->assertSame(
            1,
            preg_match('/function find_player_uses\s*\(.*?\n\}/s', $source, $match),
            'find_player_uses must exist so Edit User can load uses without owning the SQL.'
        );
        return $match[0];
    }

    public function test_find_player_uses_scopes_to_the_account_and_player(): void
    {
        $fn = $this->findPlayerUsesFn();
        $this->assertMatchesRegularExpression('/FROM uses_players/i', $fn);
        $this->assertStringContainsString('uses_players.user_id = ?', $fn);
        $this->assertStringContainsString('uses_players.player_id = ?', $fn);
        $this->assertStringContainsString('ORDER BY uses.use_date DESC', $fn);
        $this->assertStringContainsString('games.id AS artifactID', $fn);
    }

    public function test_edit_user_ranks_the_player_uses_already_on_the_page(): void
    {
        $page = $this->editUserPage();
        $include = $this->interactionsInclude();
        $this->assertStringContainsString("SHARED_PATH . '/user_interactions.php'", $page);
        $this->assertStringContainsString("PRIVATE_PATH . '/player_item_uses.php'", $include);
        $this->assertStringContainsString('find_player_uses(', $include);
        $this->assertStringContainsString('rank_items_by_player_uses(', $include);
        $this->assertStringNotContainsString(
            'FROM uses_players',
            $include,
            'The interactions include must load uses through find_player_uses, not inline SQL.'
        );
    }

    public function test_edit_user_shows_use_count_item_and_type_for_the_ranking(): void
    {
        $include = $this->interactionsInclude();
        $this->assertStringContainsString('id="most-used-items"', $include);
        $this->assertStringContainsString('Most used items with', $include);
        $this->assertStringContainsString('<th>Uses</th>', $include);
        $this->assertStringContainsString('foreach ($most_used_items as $row)', $include);
        $this->assertStringContainsString("\$row['use_count']", $include);
        $this->assertSame(
            2,
            substr_count($include, 'player_use_item_cells($row)'),
            'Both Edit User tables must share player_use_item_cells for Item and Type.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="most-used-items"[\s\S]*Most used games/i',
            $include,
            'The ranking copy must say Item, not Game.'
        );
    }

    public function test_edit_user_shows_the_ranking_before_the_chronological_list(): void
    {
        $include = $this->interactionsInclude();
        $ranking = strpos($include, 'id="most-used-items"');
        $chronological = strpos($include, 'id="useList"');
        $this->assertNotFalse($ranking);
        $this->assertNotFalse($chronological);
        $this->assertLessThan(
            $chronological,
            $ranking,
            'The most-used ranking must appear before the chronological interactions table.'
        );
    }

    public function test_edit_user_omits_the_ranking_when_the_player_has_no_uses(): void
    {
        $include = $this->interactionsInclude();
        $this->assertStringContainsString('if (!empty($most_used_items))', $include);
        $this->assertStringContainsString('id="useList"', $include);
    }
}

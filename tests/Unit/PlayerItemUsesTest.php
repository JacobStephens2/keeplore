<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/player_item_uses.php';

/**
 * Seams:
 * - rank_items_by_player_uses(): group a player's recorded uses by item
 *   and rank them most uses first
 * - ui/users/edit.php source: Edit User shows that ranking without
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

    private function editUserPage(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/users/edit.php');
    }

    public function test_edit_user_ranks_the_player_uses_already_on_the_page(): void
    {
        $page = $this->editUserPage();
        $this->assertStringContainsString("require_once(PRIVATE_PATH . '/player_item_uses.php')", $page);
        $this->assertStringContainsString('rank_items_by_player_uses(', $page);
    }

    public function test_edit_user_shows_use_count_item_and_type_for_the_ranking(): void
    {
        $page = $this->editUserPage();
        $this->assertStringContainsString('id="most-used-items"', $page);
        $this->assertStringContainsString('Most used items with', $page);
        $this->assertStringContainsString('<th>Uses</th>', $page);
        $this->assertStringContainsString('foreach ($most_used_items as $row)', $page);
        $this->assertStringContainsString("\$row['use_count']", $page);
        $this->assertStringContainsString('/artifacts/edit.php?id=', $page);
        $this->assertDoesNotMatchRegularExpression(
            '/id="most-used-items"[\s\S]*Most used games/i',
            $page,
            'The ranking copy must say Item, not Game.'
        );
    }

    public function test_edit_user_shows_the_ranking_before_the_chronological_list(): void
    {
        $page = $this->editUserPage();
        $ranking = strpos($page, 'id="most-used-items"');
        $chronological = strpos($page, 'id="useList"');
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
        $page = $this->editUserPage();
        $this->assertStringContainsString('if (!empty($most_used_items))', $page);
        $this->assertStringContainsString('id="useList"', $page);
    }
}

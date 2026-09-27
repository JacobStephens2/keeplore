<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_merge.php';

/**
 * Seams: validate_item_merge() guards a merge without the database, and
 * item_merge_candidates() orders Edit Item's "merge into this one" choices.
 */
class ItemMergeTest extends TestCase
{
    private function item(int $id, int $user_id, string $title = 'Dominion Expansions'): array
    {
        return ['id' => $id, 'user_id' => $user_id, 'Title' => $title];
    }

    public function test_two_owned_items_may_merge(): void
    {
        $this->assertSame([], validate_item_merge($this->item(4470, 8), $this->item(4471, 8), 8));
    }

    public function test_a_missing_item_self_merge_or_another_owners_item_is_refused(): void
    {
        $this->assertSame(['Both items must exist.'], validate_item_merge(null, $this->item(4471, 8), 8));
        $this->assertSame(['Both items must exist.'], validate_item_merge($this->item(4470, 8), false, 8));
        $this->assertSame(['Cannot merge an item into itself.'], validate_item_merge($this->item(4470, 8), $this->item(4470, 8), 8));
        $this->assertSame(['Both items must belong to your account.'], validate_item_merge($this->item(4470, 8), $this->item(20, 2), 8));
        $this->assertSame(['Both items must belong to your account.'], validate_item_merge($this->item(20, 2), $this->item(4471, 8), 8));
    }

    public function test_candidates_leave_out_the_survivor_and_list_same_named_items_first(): void
    {
        $items = [
            $this->item(79, 8, 'Dominion'),
            $this->item(5, 8, 'Azul'),
            $this->item(4471, 8, 'dominion expansions '),
            $this->item(4470, 8, 'Dominion Expansions'),
        ];

        $candidates = item_merge_candidates($items, $this->item(4470, 8));

        $this->assertSame([4471, 5, 79], array_map('intval', array_column($candidates, 'id')));
        $this->assertTrue($candidates[0]['same_name']);
        $this->assertFalse($candidates[1]['same_name']);
    }

    public function test_edit_item_offers_a_confirmed_merge_form(): void
    {
        $edit = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $this->assertStringContainsString("url_for('/artifacts/merge.php')", $edit);
        $this->assertMatchesRegularExpression('/<select id="merge_loser_id" name="merge_loser_id"/', $edit);
        $this->assertMatchesRegularExpression('/<input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes"/', $edit);

        $merge = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/merge.php');
        $this->assertStringContainsString('require_login()', $merge);
        $this->assertStringContainsString('merge_items(', $merge);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/item_merge.php';

/**
 * Seam: item_merge_candidates() orders Edit Item's "merge into this one"
 * choices. Items::merge is covered by the Items integration tests.
 */
class ItemMergeTest extends TestCase
{
    private function item(int $id, int $user_id, string $title = 'Dominion Expansions'): array
    {
        return ['id' => $id, 'user_id' => $user_id, 'Title' => $title];
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
        $this->assertMatchesRegularExpression('/<select id="merge_loser_id" name="merge_loser_id" required>\s*<option value="">Choose an item<\/option>/', $edit);
        $this->assertMatchesRegularExpression('/<input type="checkbox" id="merge_confirm" name="merge_confirm" value="yes"/', $edit);

        $merge = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/merge.php');
        $this->assertStringContainsString('require_login()', $merge);
        $this->assertStringContainsString('->merge($survivor_id, $loser_id)', $merge);
    }
}

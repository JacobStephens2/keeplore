<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ui/uses/interactions.php source reads the owner's uses and their
 * people through the Uses module, and item attributes through the Items
 * listing. The reads themselves are tested in tests/Integration/UsesTest.php.
 */
class ItemInteractionsTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(PROJECT_PATH . '/ui/uses/interactions.php');
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
}

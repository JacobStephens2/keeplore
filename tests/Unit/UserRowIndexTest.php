<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: getUsers.js nextUserIndex(doc).
 *
 * A new person row takes one past the highest user[i] index on the page,
 * so removing a row and pressing + never reuses an index and overwrites
 * someone already listed.
 */
class UserRowIndexTest extends TestCase
{
    public function test_next_index_follows_the_rows_in_order(): void
    {
        $this->assertSame(3, $this->nextIndex(['user0name', 'user1name', 'user2name']));
    }

    public function test_next_index_skips_past_a_removed_row(): void
    {
        $this->assertSame(3, $this->nextIndex(['user0name', 'user2name']));
    }

    public function test_next_index_is_one_with_only_the_first_row(): void
    {
        $this->assertSame(1, $this->nextIndex(['user0name']));
    }

    /** @param list<string> $ids */
    private function nextIndex(array $ids): int
    {
        $url = json_encode('file://' . PROJECT_PATH . '/ui/uses/modules/getUsers.js');
        $idsJson = json_encode($ids);
        $script = <<<JS
const inputs = {$idsJson}.map((id) => ({ id }));
globalThis.document = {
  querySelector: () => ({ addEventListener() {}, dataset: {} }),
  querySelectorAll: (sel) => (sel === 'input.user' ? inputs : []),
};
const { nextUserIndex } = await import({$url});
process.stdout.write(String(nextUserIndex()));
JS;

        $cmd = 'node --input-type=module -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);
        return (int) $raw;
    }
}

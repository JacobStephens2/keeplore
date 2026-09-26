<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Importing a BGG user's ratings stores one row per linked item that user
 * rated or commented on, replaces stale rows on rerun, and reads back per
 * owner for the Items list.
 */
final class BggRatingsImportTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    /** @var string[] */
    private array $requested = [];

    protected function setUp(): void
    {
        if (!getenv('KEEPLORE_TEST_DB_HOST')) {
            $this->markTestSkipped('Set KEEPLORE_TEST_DB_HOST to run MySQL integration tests.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli(
            getenv('KEEPLORE_TEST_DB_HOST'),
            getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
            getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
            '',
            (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306)
        );
        $this->databaseName = 'keeplore_test_' . bin2hex(random_bytes(6));
        $this->db->query('CREATE DATABASE ' . $this->databaseName);
        $this->db->select_db($this->databaseName);
        $this->db->set_charset('utf8mb4');
        $this->runSql(file_get_contents(__DIR__ . '/fixtures/proposals.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-url.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings.sql'));
        require_once PRIVATE_PATH . '/bgg_ratings.php';

        // Fixture items 10-13 belong to user 1, item 20 to user 2.
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends' WHERE id = 10");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/29107' WHERE id = 11");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/421' WHERE id = 20");
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    private function runSql(string $sql): void
    {
        $this->db->multi_query($sql);
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    /** A stand-in for BGG: Gyges rated 147154 and has no entry for 29107. */
    private function fakeBgg(array $entries): callable
    {
        return function (string $url) use ($entries) {
            $this->requested[] = $url;
            if (str_contains($url, '/users?')) {
                return '[{"userid":63428,"username":"Gyges"}]';
            }
            if (!str_contains($url, 'userid=63428')) {
                throw new \RuntimeException('unexpected user in ' . $url);
            }
            preg_match('/objectid=(\d+)/', $url, $match);
            $entry = $entries[(int) $match[1]] ?? null;
            return json_encode(['items' => $entry === null ? [] : [$entry]]);
        };
    }

    private function blueMoonEntry(float $rating, string $comment): array
    {
        return [
            'objectid' => '147154',
            'rating' => $rating,
            'rating_tstamp' => '2014-05-19 17:20:01',
            'textfield' => ['comment' => ['value' => $comment]],
        ];
    }

    public function test_import_stores_ratings_for_the_owners_linked_items(): void
    {
        $result = bgg_ratings_import($this->db, 1, 'gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'The best card game ever.'),
        ]), 0);

        $this->assertTrue($result['ok']);
        $this->assertSame('Gyges', $result['username']);
        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
        foreach ($this->requested as $url) {
            $this->assertStringNotContainsString('objectid=421', $url, "user 2's item must not be looked up");
        }

        $ratings = find_item_bgg_ratings($this->db, [10, 11, 12, 13, 20], 1);
        $this->assertSame([
            10 => ['Gyges' => [
                'rating' => 9.5,
                'comment' => 'The best card game ever.',
                'url' => 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends',
            ]],
        ], $ratings);
        $this->assertSame(['Gyges'], item_bgg_reviewers($this->db, 1));
        $this->assertSame([], item_bgg_reviewers($this->db, 2));
    }

    public function test_rerun_updates_changed_ratings_and_drops_withdrawn_ones(): void
    {
        bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'First take.'),
            29107 => $this->blueMoonEntry(6.0, 'Meh.'),
        ]), 0);

        $result = bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(10.0, 'Second take.'),
        ]), 0);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['removed']);
        $ratings = find_item_bgg_ratings($this->db, [10, 11], 1);
        $this->assertSame([10], array_keys($ratings));
        $this->assertSame(10.0, $ratings[10]['Gyges']['rating']);
        $this->assertSame('Second take.', $ratings[10]['Gyges']['comment']);
    }

    public function test_rerun_drops_the_rating_of_an_item_whose_link_was_removed(): void
    {
        bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Linked.'),
        ]), 0);
        $this->db->query('UPDATE games SET bgg_url = NULL WHERE id = 10');

        $result = bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([]), 0);

        $this->assertSame(1, $result['removed']);
        $this->assertSame([], find_item_bgg_ratings($this->db, [10, 11], 1));
    }

    public function test_deleting_the_only_rated_item_drops_the_reviewer_column(): void
    {
        bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Soon deleted.'),
        ]), 0);
        $this->db->query('DELETE FROM games WHERE id = 10');

        $this->assertSame([], item_bgg_reviewers($this->db, 1));
    }

    public function test_a_failed_lookup_keeps_that_items_earlier_rating(): void
    {
        bgg_ratings_import($this->db, 1, 'Gyges', $this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Kept through an outage.'),
        ]), 0);

        $flaky = function (string $url) {
            if (str_contains($url, '/users?')) {
                return '[{"userid":63428,"username":"Gyges"}]';
            }
            throw new \RuntimeException('BoardGameGeek HTTP 503');
        };
        $result = bgg_ratings_import($this->db, 1, 'Gyges', $flaky, 0);

        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame('Kept through an outage.', find_item_bgg_ratings($this->db, [10], 1)[10]['Gyges']['comment']);
    }

    public function test_unknown_username_imports_nothing(): void
    {
        $result = bgg_ratings_import($this->db, 1, 'nosuchuser', function () {
            return '[]';
        }, 0);

        $this->assertFalse($result['ok']);
        $this->assertSame('No BoardGameGeek user named nosuchuser.', $result['error']);
        $this->assertSame([], item_bgg_reviewers($this->db, 1));
    }
}

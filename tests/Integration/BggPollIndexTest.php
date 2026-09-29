<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Refreshing Search BGG's index from BGG's ranked lists and polls, then
 * searching it by a Best player count, a community age, or both.
 */
final class BggPollIndexTest extends TestCase
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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-bgg-poll-index.sql'));
        // Rerunning the migration must be harmless.
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-bgg-poll-index.sql'));
        require_once PRIVATE_PATH . '/bgg_poll_index.php';
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

    /**
     * A stand-in for BGG. $lists maps a subdomain family id to its ranked
     * games; $polls maps a thing id to [best ranges, votes, age] or to null
     * for a reply BGG has queued.
     */
    private function fakeBgg(array $lists, array $polls): callable
    {
        return function (string $url) use ($lists, $polls) {
            $this->requested[] = $url;
            if (str_contains($url, '/geekitem/linkeditems?')) {
                preg_match('/objectid=(\d+)/', $url, $family);
                preg_match('/pageid=(\d+)/', $url, $page);
                $games = array_slice($lists[(int) $family[1]] ?? [], ((int) $page[1] - 1) * 50, 50);
                return json_encode(['items' => array_map(fn ($game) => [
                    'objecttype' => 'thing',
                    'objectid' => (string) $game[0],
                    'name' => $game[1],
                    'yearpublished' => '2020',
                    'rank' => (string) $game[2],
                    'average' => '7.5',
                    'usersrated' => '1000',
                ], $games)]);
            }
            preg_match('/objectid=(\d+)/', $url, $thing);
            $poll = $polls[(int) $thing[1]] ?? null;
            if ($poll === null) {
                return '';
            }
            return json_encode(['item' => ['polls' => [
                'userplayers' => ['best' => $poll[0], 'totalvotes' => (string) $poll[1]],
                'playerage' => $poll[2],
            ]]]);
        };
    }

    private function refreshSample(int $perSubdomain = 50): array
    {
        $party = [[254640, 'Just One', 159], [178900, 'Codenames', 163], [2223, 'UNO', 9000]];
        $family = [[254640, 'Just One', 159], [13, 'Catan', 627]];
        $polls = [
            254640 => [[['min' => 6, 'max' => 7]], 505, '8+'],
            178900 => [[['min' => 6, 'max' => 6], ['min' => 8, 'max' => 8]], 1456, '10+'],
            2223 => [[['min' => 4, 'max' => 7]], 335, '5+'],
            13 => [[['min' => 4, 'max' => 4]], 2195, '6+'],
        ];
        return bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => $perSubdomain, 'subdomains' => [5498 => 'Party', 5499 => 'Family'], 'pause_ms' => 0],
            $this->fakeBgg([5498 => $party, 5499 => $family], $polls)
        );
    }

    public function test_refresh_stores_each_ranked_game_once_with_its_polls(): void
    {
        $result = $this->refreshSample();

        $this->assertSame(['ok' => true, 'listed' => 4, 'fetched' => 4, 'skipped' => 0, 'failed' => 0, 'lists_failed' => 0], $result);
        $row = $this->db->query('SELECT * FROM bgg_poll_games WHERE thing_id = 254640')->fetch_assoc();
        $this->assertSame('Just One', $row['name']);
        $this->assertSame('Party, Family', $row['subdomains']);
        $this->assertSame('6-7', $row['best_players']);
        $this->assertSame('505', (string) $row['player_votes']);
        $this->assertSame('8', (string) $row['community_age']);
        $best = array_column($this->db->query('SELECT players FROM bgg_poll_best_players WHERE thing_id = 254640 ORDER BY players')->fetch_all(MYSQLI_ASSOC), 'players');
        $this->assertSame(['6', '7'], array_map('strval', $best));
    }

    public function test_search_by_best_count_and_age_together(): void
    {
        $this->refreshSample();

        $found = bgg_poll_search($this->db, ['best' => 7, 'age' => 6, 'min_votes' => 0, 'skip_open' => false]);

        $this->assertSame(['UNO'], array_column($found, 'name'));
        $this->assertSame(335, $found[0]['player_votes']);
        $this->assertSame(5, $found[0]['community_age']);
        $this->assertSame('4-7', $found[0]['best_players']);
        $this->assertSame('https://boardgamegeek.com/boardgame/2223', $found[0]['url']);
    }

    public function test_search_by_best_count_alone_lists_by_bgg_rank(): void
    {
        $this->refreshSample();

        $found = bgg_poll_search($this->db, ['best' => 7, 'age' => null, 'min_votes' => 0, 'skip_open' => false]);

        $this->assertSame(['Just One', 'UNO'], array_column($found, 'name'));
    }

    public function test_search_by_age_alone_keeps_games_rated_for_that_age_or_younger(): void
    {
        $this->refreshSample();

        $found = bgg_poll_search($this->db, ['best' => null, 'age' => 6, 'min_votes' => 0, 'skip_open' => false]);

        $this->assertSame(['Catan', 'UNO'], array_column($found, 'name'));
    }

    public function test_search_leaves_out_games_with_too_few_player_poll_votes(): void
    {
        $this->refreshSample();

        $found = bgg_poll_search($this->db, ['best' => null, 'age' => 6, 'min_votes' => 1000]);

        $this->assertSame(['Catan'], array_column($found, 'name'));
    }

    public function test_rerun_skips_polls_fetched_recently_but_refetches_stale_ones(): void
    {
        $this->refreshSample();
        $this->db->query("UPDATE bgg_poll_games SET polls_fetched_at = NOW() - INTERVAL 40 DAY WHERE thing_id = 13");
        $this->requested = [];

        $result = $this->refreshSample();

        $this->assertSame(['ok' => true, 'listed' => 4, 'fetched' => 1, 'skipped' => 3, 'failed' => 0, 'lists_failed' => 0], $result);
        $this->assertCount(1, array_filter($this->requested, fn ($url) => str_contains($url, '/dynamicinfo?')));
    }

    public function test_a_queued_poll_reply_keeps_the_old_polls(): void
    {
        $this->refreshSample();
        $this->db->query('UPDATE bgg_poll_games SET polls_fetched_at = NOW() - INTERVAL 40 DAY');

        $result = bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 50, 'subdomains' => [5499 => 'Family'], 'pause_ms' => 0],
            $this->fakeBgg([5499 => [[13, 'Catan', 627]]], [])
        );

        $this->assertSame(['ok' => true, 'listed' => 1, 'fetched' => 0, 'skipped' => 0, 'failed' => 1, 'lists_failed' => 0], $result);
        $this->assertSame(['Catan'], array_column(bgg_poll_search($this->db, ['best' => 4, 'age' => null, 'min_votes' => 0, 'skip_open' => false]), 'name'));
    }

    public function test_a_list_limit_reads_only_the_top_ranked_pages(): void
    {
        $party = [];
        for ($i = 1; $i <= 120; $i++) {
            $party[] = [1000 + $i, 'Game ' . $i, $i];
        }
        $result = bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 60, 'subdomains' => [5498 => 'Party'], 'pause_ms' => 0],
            $this->fakeBgg([5498 => $party], [])
        );

        $this->assertSame(60, $result['listed']);
        $this->assertCount(2, array_filter($this->requested, fn ($url) => str_contains($url, '/linkeditems?')));
    }

    public function test_summary_counts_indexed_and_polled_games(): void
    {
        $this->refreshSample();
        $this->db->query('UPDATE bgg_poll_games SET polls_fetched_at = NULL WHERE thing_id = 13');

        $summary = bgg_poll_index_summary($this->db);

        $this->assertSame(4, $summary['games']);
        $this->assertSame(3, $summary['polled']);
        $this->assertNotNull($summary['last_fetched']);
    }

    public function test_kept_things_map_the_owners_kept_linked_items_by_bgg_id(): void
    {
        // Fixture items 10-13 belong to user 1, item 20 to user 2.
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/2223/uno' WHERE id = 10");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/13', is_kept = 0 WHERE id = 11");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/13' WHERE id = 20");

        $this->assertSame([2223 => 10], bgg_poll_kept_things($this->db, 1));
    }

    public function test_reviewer_ratings_map_the_owners_linked_items_by_bgg_id(): void
    {
        // Fixture items 10-13 belong to user 1, item 20 to user 2. Item 11 is
        // not kept, and still shows what Gyges said about it.
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/2223/uno' WHERE id = 10");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/13', is_kept = 0 WHERE id = 11");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/13' WHERE id = 20");
        bgg_ratings_store_item($this->db, 1, 10, 'Gyges', ['rating' => 6.5, 'comment' => null, 'rated_at' => null]);
        bgg_ratings_store_item($this->db, 1, 11, 'Gyges', ['rating' => null, 'comment' => 'Too long.', 'rated_at' => null]);
        bgg_ratings_store_item($this->db, 2, 20, 'Gyges', ['rating' => 9.0, 'comment' => 'Not user 1s.', 'rated_at' => null]);

        $ratings = bgg_reviews_by_thing($this->db, 1);

        $this->assertSame([2223, 13], array_keys($ratings));
        $this->assertSame(6.5, $ratings[2223]['Gyges']['rating']);
        $this->assertSame(10, $ratings[2223]['Gyges']['artifact_id']);
        $this->assertNull($ratings[13]['Gyges']['rating']);
        $this->assertSame('Too long.', $ratings[13]['Gyges']['comment']);
    }

    public function test_a_game_off_every_list_leaves_the_index(): void
    {
        $this->refreshSample();

        bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 50, 'subdomains' => [5499 => 'Family'], 'pause_ms' => 0],
            $this->fakeBgg([5499 => [[13, 'Catan', 627]]], [])
        );

        $this->assertSame(['13'], array_map('strval', array_column($this->db->query('SELECT thing_id FROM bgg_poll_games')->fetch_all(MYSQLI_ASSOC), 'thing_id')));
        $this->assertSame('0', (string) $this->db->query('SELECT COUNT(*) c FROM bgg_poll_best_players WHERE thing_id <> 13')->fetch_assoc()['c']);
    }

    public function test_a_failed_list_page_keeps_every_game_and_counts_as_failed(): void
    {
        $this->refreshSample();
        $bgg = $this->fakeBgg([5499 => [[13, 'Catan', 627]]], []);

        $result = bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 50, 'subdomains' => [5498 => 'Party', 5499 => 'Family'], 'pause_ms' => 0],
            function (string $url) use ($bgg) {
                return str_contains($url, 'objectid=5498') ? '' : $bgg($url);
            }
        );

        $this->assertSame(1, $result['lists_failed']);
        $this->assertSame('4', (string) $this->db->query('SELECT COUNT(*) c FROM bgg_poll_games')->fetch_assoc()['c']);
    }

    public function test_an_open_ended_best_matches_larger_groups(): void
    {
        bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 50, 'subdomains' => [5498 => 'Party'], 'pause_ms' => 0],
            $this->fakeBgg([5498 => [[1, 'Big Party', 10]]], [1 => [[['min' => 9, 'max' => null]], 40, '10+']])
        );

        $found = bgg_poll_search($this->db, ['best' => 12, 'age' => null, 'min_votes' => 0, 'skip_open' => false]);

        $this->assertSame(['Big Party'], array_column($found, 'name'));
        $this->assertSame('9+', $found[0]['best_players']);
    }

    public function test_leaving_out_open_ended_best_keeps_only_counts_the_votes_name(): void
    {
        bgg_poll_index_refresh(
            $this->db,
            ['perSubdomain' => 50, 'subdomains' => [5498 => 'Party'], 'pause_ms' => 0],
            $this->fakeBgg([5498 => [[1, 'Four Plus', 10], [2, 'Nine Plus', 20], [3, 'Up To Nine', 30], [4, 'Three And Four Plus', 40]]], [
                1 => [[['min' => 4, 'max' => null]], 40, '10+'],
                2 => [[['min' => 9, 'max' => null]], 40, '10+'],
                3 => [[['min' => 6, 'max' => 9]], 40, '10+'],
                4 => [[['min' => 3, 'max' => 3], ['min' => 4, 'max' => null]], 40, '10+'],
            ])
        );

        $all = bgg_poll_search($this->db, ['best' => 9, 'age' => null, 'min_votes' => 0, 'skip_open' => false]);
        $stated = bgg_poll_search($this->db, ['best' => 9, 'age' => null, 'min_votes' => 0, 'skip_open' => true]);
        $atFour = bgg_poll_search($this->db, ['best' => 4, 'age' => null, 'min_votes' => 0, 'skip_open' => true]);

        $this->assertSame(['Four Plus', 'Nine Plus', 'Up To Nine', 'Three And Four Plus'], array_column($all, 'name'));
        $this->assertSame(['Nine Plus', 'Up To Nine'], array_column($stated, 'name'));
        $this->assertSame(['Four Plus', 'Three And Four Plus'], array_column($atFour, 'name'));
    }
}

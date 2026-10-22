<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Search BGG's results: one module owns the row the page prints and the
 * browser sorts. The poll query, the Kept read and the ratings read stay
 * where they are; this test calls only the results module.
 */
final class BggSearchResultsTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;

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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-username.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-bgg-poll-index.sql'));
        require_once PRIVATE_PATH . '/bgg_search_results.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_neither_filter_returns_no_rows_and_no_reviewers(): void
    {
        $this->linkItem(10, 'https://boardgamegeek.com/boardgame/301/kept', true);
        $this->addReview(1, 10, 'Gyges', 8.0, 'Great');

        $result = bgg_search_results($this->db, 1, $this->filters(null, null));

        $this->assertSame([
            'searched' => false,
            'reviewers' => [],
            'cap' => 200,
            'more' => false,
            'rows' => [],
        ], $result);
    }

    public function test_a_search_returns_each_rows_sort_value_and_display_value(): void
    {
        // Two Kept items link to the same game. The lower item id wins, for
        // Kept and for the review. Item 11 is linked and not Kept.
        $this->linkItem(10, 'https://boardgamegeek.com/boardgame/301/kept', true);
        $this->linkItem(12, 'https://boardgamegeek.com/boardgame/301/copy', true);
        $this->linkItem(11, 'https://boardgamegeek.com/boardgame/302/comment', false);
        $this->linkItem(20, 'https://boardgamegeek.com/boardgame/303/open', true);
        $this->addReview(1, 10, 'Ada', 7.5, '');
        $this->addReview(1, 10, 'Gyges', 8.0, 'Great');
        $this->addReview(1, 12, 'Gyges', 3.0, 'Worse copy');
        $this->addReview(1, 11, 'Gyges', null, 'Too long.');
        $this->addReview(2, 20, 'Gyges', 9.0, 'Not yours');

        $this->insertGame(301, 'Kept Game', 2018, 100, 8.0, 'Party, Family', '6-7', 100, 8, [6, 7]);
        $this->insertGame(302, 'Comment Only', null, 200, 7.5, 'Party', '6, 8', 1456, 10, [6, 8]);
        $this->insertGame(305, 'Blank Best', null, 300, null, '', '', 12, 4, []);
        $this->insertGame(303, 'Open Ended', 1999, 1500, 11.5, 'Strategy', '9+', 40, 8, range(9, 20));
        $this->insertGame(306, 'No Rank', null, null, 5.5, '', '4', 80, 6, [4]);
        $this->insertGame(307, 'No Community Age', null, 50, 6.0, '', '6-7', 9, null, [6, 7]);
        $this->insertGame(308, 'Too Old', null, 1, 6.0, '', '2', 5000, 13, [2]);

        $byAge = bgg_search_results($this->db, 1, $this->filters(null, 12));

        $this->assertTrue($byAge['searched']);
        $this->assertSame(['Ada', 'Gyges'], $byAge['reviewers']);
        $this->assertSame(200, $byAge['cap']);
        $this->assertFalse($byAge['more']);
        $this->assertSame(
            ['Kept Game', 'Comment Only', 'Blank Best', 'Open Ended', 'No Rank'],
            array_column($byAge['rows'], 'name')
        );
        $this->assertSame($this->keptRow(), $byAge['rows'][0]);
        $this->assertSame($this->commentOnlyRow(), $byAge['rows'][1]);
        $this->assertSame($this->blankBestRow(), $byAge['rows'][2]);
        $this->assertSame($this->openEndedRow(), $byAge['rows'][3]);
        $this->assertSame($this->noRankRow(), $byAge['rows'][4]);

        $byBest = bgg_search_results($this->db, 1, $this->filters(6, null));
        $this->assertSame(
            ['No Community Age', 'Kept Game', 'Comment Only'],
            array_column($byBest['rows'], 'name')
        );
        $this->assertNull($byBest['rows'][0]['age']);
        $this->assertSame('', $byBest['rows'][0]['age_text']);
        $this->assertSame(6, $byBest['rows'][0]['best']);
        $this->assertSame(0, $byBest['rows'][0]['id']);

        $byBoth = bgg_search_results($this->db, 1, $this->filters(6, 8));
        $this->assertSame(['Kept Game'], array_column($byBoth['rows'], 'name'));

        $byVotes = bgg_search_results($this->db, 1, $this->filters(null, 12, 1000));
        $this->assertSame(['Comment Only'], array_column($byVotes['rows'], 'name'));
    }

    public function test_the_search_stops_at_200_and_reports_that_more_matched(): void
    {
        for ($rank = 1; $rank <= 201; $rank++) {
            $this->insertGame(1000 + $rank, 'Rank ' . $rank, null, $rank, null, '', '4', 10, 5, []);
        }

        $result = bgg_search_results($this->db, 1, $this->filters(null, 6));

        $this->assertTrue($result['more']);
        $this->assertSame(200, $result['cap']);
        $this->assertCount(200, $result['rows']);
        $this->assertSame('Rank 1', $result['rows'][0]['name']);
        $this->assertSame(0, $result['rows'][0]['id']);
        $this->assertSame(1001, $result['rows'][0]['thing_id']);
        $this->assertSame('Rank 200', $result['rows'][199]['name']);
        $this->assertSame(199, $result['rows'][199]['id']);
        $this->assertNotContains('Rank 201', array_column($result['rows'], 'name'));
    }

    private function filters(?int $best, ?int $age, int $minVotes = 0): array
    {
        return ['best' => $best, 'age' => $age, 'min_votes' => $minVotes, 'skip_open' => false];
    }

    private function linkItem(int $itemId, string $url, bool $kept): void
    {
        $keptFlag = $kept ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE games SET bgg_url = ?, is_kept = ? WHERE id = ?');
        $stmt->bind_param('sii', $url, $keptFlag, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private function addReview(int $userId, int $itemId, string $reviewer, ?float $rating, string $comment): void
    {
        $ratingSql = $rating === null ? 'NULL' : (string) $rating;
        $this->db->query(sprintf(
            "INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating, comment, is_manual) VALUES (%d, %d, '%s', %s, '%s', 1)",
            $userId,
            $itemId,
            $this->db->real_escape_string($reviewer),
            $ratingSql,
            $this->db->real_escape_string($comment)
        ));
    }

    /**
     * @param list<int> $bestCounts
     */
    private function insertGame(
        int $thingId,
        string $name,
        ?int $year,
        ?int $rank,
        ?float $average,
        string $subdomains,
        string $best,
        int $votes,
        ?int $age,
        array $bestCounts
    ): void {
        $this->db->query(sprintf(
            "INSERT INTO bgg_poll_games (thing_id, name, year_published, bgg_rank, average, users_rated, subdomains, best_players, player_votes, community_age, polls_fetched_at) VALUES (%d, '%s', %s, %s, %s, 0, '%s', '%s', %d, %s, NOW())",
            $thingId,
            $this->db->real_escape_string($name),
            $year === null ? 'NULL' : (string) $year,
            $rank === null ? 'NULL' : (string) $rank,
            $average === null ? 'NULL' : (string) $average,
            $this->db->real_escape_string($subdomains),
            $this->db->real_escape_string($best),
            $votes,
            $age === null ? 'NULL' : (string) $age
        ));
        foreach ($bestCounts as $count) {
            $this->db->query('INSERT INTO bgg_poll_best_players (thing_id, players) VALUES (' . $thingId . ', ' . (int) $count . ')');
        }
    }

    private function keptRow(): array
    {
        return [
            'id' => 0,
            'thing_id' => 301,
            'name' => 'Kept Game',
            'year' => 2018,
            'subdomains' => 'Party, Family',
            'url' => 'https://boardgamegeek.com/boardgame/301',
            'best' => 6,
            'best_text' => '6-7',
            'votes' => 100,
            'votes_text' => '100',
            'age' => 8,
            'age_text' => '8+',
            'rank' => 100,
            'rank_text' => '100',
            'average' => 8.0,
            'average_text' => '8',
            'kept' => true,
            'kept_item_id' => 10,
            'kept_text' => 'Kept',
            'reviews' => [
                'Ada' => ['text' => '7.5', 'item_id' => 10, 'comment' => ''],
                'Gyges' => ['text' => '8', 'item_id' => 10, 'comment' => 'Great'],
            ],
            'bgg_rating:Ada' => 7.5,
            'bgg_rating:Gyges' => 8.0,
        ];
    }

    private function commentOnlyRow(): array
    {
        return [
            'id' => 1,
            'thing_id' => 302,
            'name' => 'Comment Only',
            'year' => null,
            'subdomains' => 'Party',
            'url' => 'https://boardgamegeek.com/boardgame/302',
            'best' => 6,
            'best_text' => '6, 8',
            'votes' => 1456,
            'votes_text' => '1,456',
            'age' => 10,
            'age_text' => '10+',
            'rank' => 200,
            'rank_text' => '200',
            'average' => 7.5,
            'average_text' => '7.5',
            'kept' => false,
            'kept_item_id' => null,
            'kept_text' => '',
            'reviews' => [
                'Ada' => null,
                'Gyges' => ['text' => '', 'item_id' => 11, 'comment' => 'Too long.'],
            ],
            'bgg_rating:Ada' => null,
            'bgg_rating:Gyges' => null,
        ];
    }

    private function blankBestRow(): array
    {
        return [
            'id' => 2,
            'thing_id' => 305,
            'name' => 'Blank Best',
            'year' => null,
            'subdomains' => '',
            'url' => 'https://boardgamegeek.com/boardgame/305',
            'best' => null,
            'best_text' => '',
            'votes' => 12,
            'votes_text' => '12',
            'age' => 4,
            'age_text' => '4+',
            'rank' => 300,
            'rank_text' => '300',
            'average' => null,
            'average_text' => '',
            'kept' => false,
            'kept_item_id' => null,
            'kept_text' => '',
            'reviews' => [
                'Ada' => null,
                'Gyges' => null,
            ],
            'bgg_rating:Ada' => null,
            'bgg_rating:Gyges' => null,
        ];
    }

    private function openEndedRow(): array
    {
        return [
            'id' => 3,
            'thing_id' => 303,
            'name' => 'Open Ended',
            'year' => 1999,
            'subdomains' => 'Strategy',
            'url' => 'https://boardgamegeek.com/boardgame/303',
            'best' => 9,
            'best_text' => '9+',
            'votes' => 40,
            'votes_text' => '40',
            'age' => 8,
            'age_text' => '8+',
            'rank' => 1500,
            'rank_text' => '1,500',
            'average' => 11.5,
            'average_text' => '',
            'kept' => false,
            'kept_item_id' => null,
            'kept_text' => '',
            'reviews' => [
                'Ada' => null,
                'Gyges' => null,
            ],
            'bgg_rating:Ada' => null,
            'bgg_rating:Gyges' => null,
        ];
    }

    private function noRankRow(): array
    {
        return [
            'id' => 4,
            'thing_id' => 306,
            'name' => 'No Rank',
            'year' => null,
            'subdomains' => '',
            'url' => 'https://boardgamegeek.com/boardgame/306',
            'best' => 4,
            'best_text' => '4',
            'votes' => 80,
            'votes_text' => '80',
            'age' => 6,
            'age_text' => '6+',
            'rank' => null,
            'rank_text' => '',
            'average' => 5.5,
            'average_text' => '5.5',
            'kept' => false,
            'kept_item_id' => null,
            'kept_text' => '',
            'reviews' => [
                'Ada' => null,
                'Gyges' => null,
            ],
            'bgg_rating:Ada' => null,
            'bgg_rating:Gyges' => null,
        ];
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
}

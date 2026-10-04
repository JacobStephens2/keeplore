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
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
        // Rerunning the migration must be harmless.
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-username.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-username.sql'));
        require_once PRIVATE_PATH . '/classes/BggRatings.php';

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

    /** The owner's BGG ratings module, asking $bgg instead of BoardGameGeek. */
    private function ratings(?callable $bgg = null, int $owner = 1): \BggRatings
    {
        return new \BggRatings($this->db, $owner, $bgg ?? function (string $url) {
            throw new \RuntimeException('BGG must not be asked: ' . $url);
        });
    }

    /** What $act threw; fails when it throws nothing. */
    private function refusal(callable $act): \Throwable
    {
        try {
            $act();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Expected a refusal.');
    }

    private function assertRefused(string $class, string $message, \Throwable $refusal): void
    {
        $this->assertInstanceOf($class, $refusal);
        $this->assertSame($message, $refusal->getMessage());
    }

    public function test_import_stores_ratings_for_the_owners_linked_items(): void
    {
        $result = $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'The best card game ever.'),
        ]))->import('gyges', 0);

        $this->assertSame('Gyges', $result['username']);
        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);
        foreach ($this->requested as $url) {
            $this->assertStringNotContainsString('objectid=421', $url, "user 2's item must not be looked up");
        }

        $ratings = $this->ratings()->forItems([10, 11, 12, 13, 20]);
        $this->assertSame([
            10 => ['Gyges' => [
                'rating' => 9.5,
                'comment' => 'The best card game ever.',
                'url' => 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends',
                'manual' => false,
            ]],
        ], $ratings);
        $this->assertSame(['Gyges'], $this->ratings()->reviewers());
        $this->assertSame([], $this->ratings(null, 2)->reviewers());
    }

    public function test_rerun_updates_changed_ratings_and_drops_withdrawn_ones(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'First take.'),
            29107 => $this->blueMoonEntry(6.0, 'Meh.'),
        ]))->import('Gyges', 0);

        $result = $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(10.0, 'Second take.'),
        ]))->import('Gyges', 0);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['removed']);
        $ratings = $this->ratings()->forItems([10, 11]);
        $this->assertSame([10], array_keys($ratings));
        $this->assertSame(10.0, $ratings[10]['Gyges']['rating']);
        $this->assertSame('Second take.', $ratings[10]['Gyges']['comment']);
    }

    public function test_rerun_drops_the_rating_of_an_item_whose_link_was_removed(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Linked.'),
        ]))->import('Gyges', 0);
        $this->db->query('UPDATE games SET bgg_url = NULL WHERE id = 10');

        $result = $this->ratings($this->fakeBgg([]))->import('Gyges', 0);

        $this->assertSame(1, $result['removed']);
        $this->assertSame([], $this->ratings()->forItems([10, 11]));
    }

    public function test_deleting_the_only_rated_item_drops_the_reviewer_column(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Soon deleted.'),
        ]))->import('Gyges', 0);
        $this->db->query('DELETE FROM games WHERE id = 10');

        $this->assertSame([], $this->ratings()->reviewers());
    }

    public function test_a_failed_lookup_keeps_that_items_earlier_rating(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Kept through an outage.'),
        ]))->import('Gyges', 0);

        $flaky = function (string $url) {
            if (str_contains($url, '/users?')) {
                return '[{"userid":63428,"username":"Gyges"}]';
            }
            throw new \RuntimeException('BoardGameGeek HTTP 503');
        };
        $result = $this->ratings($flaky)->import('Gyges', 0);

        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame('Kept through an outage.', $this->ratings()->forItems([10])[10]['Gyges']['comment']);
    }

    public function test_one_item_import_stores_that_items_rating_only(): void
    {
        $message = $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Imported alone.'),
            29107 => $this->blueMoonEntry(6.0, 'Not asked for.'),
        ]))->request(10, 'gyges');

        $this->assertSame('Gyges rated it 9.5 out of 10.', $message);
        $this->assertSame([10], array_keys($this->ratings()->forItems([10, 11])));
        $this->assertSame('Imported alone.', $this->ratings()->forItems([10])[10]['Gyges']['comment']);
    }

    public function test_one_item_import_with_no_bgg_entry_clears_the_old_one(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Withdrawn later.'),
        ]))->request(10, 'Gyges');

        $message = $this->ratings($this->fakeBgg([]))->request(10, 'Gyges');

        $this->assertSame('Gyges has not rated or commented on this item on BoardGameGeek.', $message);
        $this->assertSame([], $this->ratings()->forItems([10]));
    }

    public function test_one_item_import_refuses_unlinked_and_other_owners_items(): void
    {
        $ratings = $this->ratings($this->fakeBgg([421 => $this->blueMoonEntry(7.0, 'Private.')]));

        $this->assertRefused(\InvalidArgumentException::class, 'Add a BoardGameGeek link to this item first.',
            $this->refusal(fn () => $ratings->request(12, 'Gyges')));
        $this->assertRefused(\OutOfBoundsException::class, 'Item not found.',
            $this->refusal(fn () => $ratings->request(20, 'Gyges')));
        $this->assertSame([], $this->ratings(null, 2)->forItems([20]));
    }

    public function test_one_item_import_keeps_the_old_rating_when_bgg_fails(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Survives.'),
        ]))->request(10, 'Gyges');

        $refusal = $this->refusal(fn () => $this->ratings(function (string $url) {
            if (str_contains($url, '/users?')) {
                return '[{"userid":63428,"username":"Gyges"}]';
            }
            throw new \RuntimeException('BoardGameGeek HTTP 503');
        })->request(10, 'Gyges'));

        $this->assertRefused(\BggUnreachable::class, 'Could not reach BoardGameGeek.', $refusal);
        $this->assertInstanceOf(\RuntimeException::class, $refusal);
        $this->assertSame('Survives.', $this->ratings()->forItems([10])[10]['Gyges']['comment']);
    }

    public function test_an_empty_or_garbled_bgg_answer_keeps_the_old_rating(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'Not wiped by a queued reply.'),
        ]))->request(10, 'Gyges');

        $queued = $this->ratings(function (string $url) {
            return str_contains($url, '/users?') ? '[{"userid":63428,"username":"Gyges"}]' : '';
        });
        $refusal = $this->refusal(fn () => $queued->request(10, 'Gyges'));
        $bulk = $queued->import('Gyges', 0);

        $this->assertInstanceOf(\BggUnreachable::class, $refusal);
        $this->assertSame(2, $bulk['failed']);
        $this->assertSame(0, $bulk['removed']);
        $this->assertSame('Not wiped by a queued reply.', $this->ratings()->forItems([10])[10]['Gyges']['comment']);
    }

    private function importGygesOnBlueMoon(): void
    {
        $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'The best card game ever.'),
        ]))->request(10, 'Gyges');
    }

    public function test_owner_edits_an_imported_rating_and_comment(): void
    {
        $this->importGygesOnBlueMoon();

        $message = $this->ratings()->save(10, 'gyges', ' 8.5 ', "  Still great.\nJust not the best.  ");

        $this->assertSame("Saved Gyges' rating and comment.", $message);
        $saved = $this->ratings()->forItems([10])[10]['Gyges'];
        $this->assertSame(8.5, $saved['rating']);
        $this->assertSame("Still great.\nJust not the best.", $saved['comment']);
        $rated_at = $this->db->query('SELECT rated_at FROM item_bgg_ratings WHERE artifact_id = 10')->fetch_row()[0];
        $this->assertNull($rated_at, "an edited score is no longer BGG's, so it drops BGG's rating date");
    }

    public function test_owner_adds_a_rating_to_a_linked_item_without_one(): void
    {
        $this->importGygesOnBlueMoon();

        $this->ratings()->save(11, 'Gyges', '', 'Comment only.');

        $this->assertSame(['Gyges' => [
            'rating' => null,
            'comment' => 'Comment only.',
            'url' => 'https://boardgamegeek.com/boardgame/29107',
            'manual' => true,
        ]], $this->ratings()->forItems([11])[11]);
    }

    public function test_clearing_both_fields_removes_the_rating(): void
    {
        $this->importGygesOnBlueMoon();

        $message = $this->ratings()->save(10, 'Gyges', ' ', '');

        $this->assertSame("Removed Gyges' rating and comment.", $message);
        $this->assertSame([], $this->ratings()->forItems([10]));
    }

    public function test_a_rating_outside_one_to_ten_changes_nothing(): void
    {
        $this->importGygesOnBlueMoon();

        $refusal = $this->refusal(fn () => $this->ratings()->save(10, 'Gyges', '10.5', 'Overwritten?'));

        $this->assertRefused(\InvalidArgumentException::class, 'A rating is a number from 1 to 10.', $refusal);
        $this->assertSame(9.5, $this->ratings()->forItems([10])[10]['Gyges']['rating']);
    }

    public function test_owner_adds_a_rating_to_an_item_with_no_bgg_link(): void
    {
        $this->importGygesOnBlueMoon();

        // Item 12 has no BGG link and no Gyges row.
        $this->ratings()->save(12, 'Gyges', '7', 'Fun with kids.');

        $this->assertSame(
            ['rating' => 7.0, 'comment' => 'Fun with kids.', 'url' => '', 'manual' => true],
            $this->ratings()->forItems([12])[12]['Gyges']
        );
    }

    public function test_bulk_import_leaves_hand_entries_alone(): void
    {
        $this->importGygesOnBlueMoon();
        $this->ratings()->save(10, 'Gyges', '4', 'My own take.');
        $this->ratings()->save(11, 'Gyges', '5', 'BGG has nothing.');
        $this->ratings()->save(12, 'Gyges', '6', 'No link at all.');

        $result = $this->ratings($this->fakeBgg([
            147154 => $this->blueMoonEntry(9.5, 'From BGG.'),
        ]))->import('Gyges', 0);

        $this->assertSame(0, $result['removed']);
        $ratings = $this->ratings()->forItems([10, 11, 12]);
        $this->assertSame('My own take.', $ratings[10]['Gyges']['comment']);
        $this->assertSame('BGG has nothing.', $ratings[11]['Gyges']['comment']);
        $this->assertSame('No link at all.', $ratings[12]['Gyges']['comment']);
    }

    public function test_requesting_one_item_keeps_a_hand_entry_bgg_has_nothing_for(): void
    {
        $this->importGygesOnBlueMoon();
        $this->ratings()->save(11, 'Gyges', '5', 'Mine.');

        $message = $this->ratings($this->fakeBgg([]))->request(11, 'Gyges');

        $this->assertSame('Gyges has not rated or commented on this item on BoardGameGeek, so your entry stays.', $message);
        $this->assertSame('Mine.', $this->ratings()->forItems([11])[11]['Gyges']['comment']);
    }

    public function test_requesting_one_item_replaces_a_hand_entry_with_bgg_data(): void
    {
        $this->importGygesOnBlueMoon();
        $this->ratings()->save(10, 'Gyges', '5', 'Mine.');

        $this->importGygesOnBlueMoon();

        $rating = $this->ratings()->forItems([10])[10]['Gyges'];
        $this->assertSame('The best card game ever.', $rating['comment']);
        $this->assertFalse($rating['manual']);
    }

    public function test_editing_refuses_unknown_reviewers_and_unowned_items(): void
    {
        $this->importGygesOnBlueMoon();

        $this->assertRefused(\InvalidArgumentException::class, 'Someone is not one of your BoardGameGeek reviewers.',
            $this->refusal(fn () => $this->ratings()->save(10, 'Someone', '7', '')));
        $this->assertRefused(\OutOfBoundsException::class, 'Item not found.',
            $this->refusal(fn () => $this->ratings()->save(20, 'Gyges', '7', '')));
        $this->assertRefused(\InvalidArgumentException::class, 'Gyges is not one of your BoardGameGeek reviewers.',
            $this->refusal(fn () => $this->ratings(null, 2)->save(20, 'Gyges', '7', '')));
        $this->assertSame([], $this->ratings(null, 2)->forItems([20]));
    }

    public function test_another_owners_item_is_not_found_by_save_or_request(): void
    {
        $this->importGygesOnBlueMoon();
        $bgg = $this->fakeBgg([421 => $this->blueMoonEntry(7.0, 'Not yours.')]);

        foreach ([
            fn () => $this->ratings($bgg)->save(20, 'Gyges', '7', 'Not yours.'),
            fn () => $this->ratings($bgg)->request(20, 'Gyges'),
        ] as $act) {
            $this->assertRefused(\OutOfBoundsException::class, 'Item not found.', $this->refusal($act));
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM item_bgg_ratings WHERE artifact_id = 20')->fetch_row()[0]);
        foreach ($this->requested as $url) {
            $this->assertStringNotContainsString('objectid=421', $url, "user 2's item must not be looked up");
        }
    }

    public function test_unknown_username_imports_nothing(): void
    {
        $refusal = $this->refusal(fn () => $this->ratings(function () {
            return '[]';
        })->import('nosuchuser', 0));

        $this->assertRefused(\InvalidArgumentException::class, 'No BoardGameGeek user named nosuchuser.', $refusal);
        $this->assertSame([], $this->ratings()->reviewers());
    }

    public function test_overall_rating_import_stores_the_average_and_keeps_it_through_an_outage(): void
    {
        $this->db->query("UPDATE games SET BGG_Rat = '5.50' WHERE id = 11");
        $this->db->query("UPDATE games SET bgg_url = 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends' WHERE id = 13");
        $this->db->query("UPDATE games SET BGG_Rat = '9.00' WHERE id = 20");

        $calls = 0;
        $result = $this->ratings(function (string $url) use (&$calls) {
            $calls++;
            if (str_contains($url, 'objectid=147154')) {
                return json_encode(['item' => ['stats' => ['average' => '7.654', 'baverage' => '6.1']]]);
            }
            if (str_contains($url, 'objectid=29107')) {
                throw new \RuntimeException('down');
            }
            throw new \RuntimeException('unexpected ' . $url);
        })->importAverages(0);

        $this->assertSame(2, $calls);
        $this->assertSame(['checked' => 3, 'imported' => 2, 'cleared' => 0, 'failed' => 1], $result);
        $this->assertSame('7.65', $this->rating(10));
        $this->assertSame('7.65', $this->rating(13));
        $this->assertSame('5.50', $this->rating(11));
        $this->assertNull($this->rating(12));
        $this->assertSame('9.00', $this->rating(20));

        $cleared = $this->ratings(function (string $url) {
            if (str_contains($url, 'objectid=147154')) {
                return json_encode(['item' => ['stats' => ['average' => '']]]);
            }
            return '{"queued":true}';
        })->importAverages(0);
        $this->assertSame(2, $cleared['cleared']);
        $this->assertSame(1, $cleared['failed']);
        $this->assertNull($this->rating(10));
        $this->assertNull($this->rating(13));
        $this->assertSame('5.50', $this->rating(11));
    }

    public function test_profile_reviewer_is_stored_as_bgg_spells_it(): void
    {
        $this->assertNull($this->ratings()->ownReviewer());

        $message = $this->ratings($this->fakeBgg([]))->setOwnReviewer('  gyges ');

        $this->assertSame('Your BoardGameGeek reviewer is now Gyges.', $message);
        $this->assertSame('Gyges', $this->ratings()->ownReviewer());
        $this->assertNull($this->ratings(null, 2)->ownReviewer());
    }

    public function test_profile_reviewer_left_as_is_does_not_ask_bgg(): void
    {
        $this->ratings($this->fakeBgg([]))->setOwnReviewer('Gyges');

        $message = $this->ratings()->setOwnReviewer('GYGES');

        $this->assertNull($message);
        $this->assertSame('Gyges', $this->ratings()->ownReviewer());
    }

    public function test_profile_reviewer_left_blank_says_nothing(): void
    {
        $this->assertNull($this->ratings()->setOwnReviewer(''));
        $this->assertNull($this->ratings()->ownReviewer());
    }

    public function test_profile_reviewer_clears_when_blank_and_keeps_the_ratings(): void
    {
        $this->ratings($this->fakeBgg([]))->setOwnReviewer('Gyges');
        $this->importGygesOnBlueMoon();

        $message = $this->ratings()->setOwnReviewer(' ');

        $this->assertSame('You no longer have a BoardGameGeek reviewer.', $message);
        $this->assertNull($this->ratings()->ownReviewer());
        $this->assertSame(['Gyges'], $this->ratings()->reviewers());
    }

    public function test_profile_reviewer_must_be_a_bgg_user(): void
    {
        $this->ratings($this->fakeBgg([]))->setOwnReviewer('Gyges');

        $unknown = $this->refusal(fn () => $this->ratings(function () {
            return '[]';
        })->setOwnReviewer('nosuchuser'));
        $offline = $this->refusal(fn () => $this->ratings(function () {
            throw new \RuntimeException('offline');
        })->setOwnReviewer('Someone'));
        $too_long = $this->refusal(fn () => $this->ratings($this->fakeBgg([]))->setOwnReviewer(str_repeat('x', 65)));

        $this->assertRefused(\InvalidArgumentException::class, 'No BoardGameGeek user named nosuchuser.', $unknown);
        $this->assertRefused(\BggUnreachable::class, 'Could not reach BoardGameGeek.', $offline);
        $this->assertRefused(\InvalidArgumentException::class, 'A BoardGameGeek username is at most 64 characters.', $too_long);
        $this->assertSame('Gyges', $this->ratings()->ownReviewer());
    }

    public function test_a_bgg_outage_leaves_the_profile_reviewer_unchanged(): void
    {
        $this->ratings($this->fakeBgg([]))->setOwnReviewer('Gyges');

        $refusal = $this->refusal(fn () => $this->ratings(function () {
            throw new \RuntimeException('BoardGameGeek HTTP 503');
        })->setOwnReviewer('Someone'));

        $this->assertRefused(\BggUnreachable::class, 'Could not reach BoardGameGeek.', $refusal);
        $this->assertSame('Gyges', $this->ratings()->ownReviewer());
        $this->assertSame('Gyges', $this->db->query('SELECT bgg_username FROM users WHERE id = 1')->fetch_row()[0]);
    }

    public function test_profile_reviewer_gets_a_column_and_hand_entry_before_any_import(): void
    {
        $this->ratings($this->fakeBgg([]))->setOwnReviewer('Gyges');
        // Other, an earlier reviewer, rated item 11 (thing 29107) on BGG.
        $this->ratings(function (string $url) {
            if (str_contains($url, '/users?')) {
                return '[{"userid":777,"username":"Other"}]';
            }
            return json_encode(['items' => str_contains($url, 'objectid=29107') ? [['rating' => 7.0]] : []]);
        })->import('Other', 0);

        $this->assertSame(['Gyges', 'Other'], $this->ratings()->reviewers());
        $this->assertSame([], $this->ratings(null, 2)->reviewers());

        $this->ratings()->save(12, 'gyges', '8', '');

        $this->assertSame(8.0, $this->ratings()->forItems([12])[12]['Gyges']['rating']);
        $this->assertSame(['Gyges', 'Other'], $this->ratings()->reviewers());
    }

    private function rating(int $id): ?string
    {
        $value = $this->db->query('SELECT BGG_Rat FROM games WHERE id = ' . $id)->fetch_row()[0];
        return $value === null ? null : (string) $value;
    }
}

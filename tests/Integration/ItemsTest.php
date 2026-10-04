<?php

namespace Tests\Integration;

use ItemInvalid;
use Items;
use PHPUnit\Framework\TestCase;

/**
 * Seam: Items, the owner's Items. Another owner's Item is never found,
 * changed or deleted. Create and update share one set of defaults,
 * validation and normalizers, and write the Item with its tags in one
 * transaction. The kept, to get rid of and snooze writes change only their
 * own field, whatever the rest of the Item holds. ItemsDeleteAndMergeTest
 * covers delete and merge.
 */
final class ItemsTest extends TestCase
{
    private ?\mysqli $db = null;
    private string $databaseName;
    private Items $items;

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
        // Items write every item column, so games and owner-scoped types
        // come from the app's schema rather than the shared fixture.
        $this->runSql('DROP TABLE games, types');
        $this->runSql($this->schemaTable('types') . $this->schemaTable('games'));
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, type, is_kept, is_digital, is_physical,
                Candidate, CandidateGroupDate, UsedRecUserCt, image_url, SS, MnT, MxT, MnP, MxP, Age, Acq)
            VALUES
            (10, 1, 'Catan', 1, 'board-game', 1, 1, 1, 'yes', '2020-05-01', '3',
                'https://cf.geekdo-images.com/catan.jpg', '04', 60, 120, 3, 4, 10, '2020-01-01'),
            (11, 1, 'Azul', 1, 'board-game', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01'),
            (20, 2, 'Private item', 3, 'card game', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01')");
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'beach-safe'), (1, 10, 'family'), (1, 11, 'family'), (2, 20, 'mine')");
        require_once PRIVATE_PATH . '/classes/Items.php';
        (new \Preferences($this->db, 1))->save(['default_use_interval' => 120]);
        $this->items = new Items($this->db, 1);
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

    private function schemaTable(string $table): string
    {
        $schema = file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=[^;]*;/s', $schema, $match);
        return $match[0];
    }

    private function column(string $sql): array
    {
        return array_map('intval', array_column($this->db->query($sql)->fetch_all(MYSQLI_NUM), 0));
    }

    private function tagsOf(int $itemId): array
    {
        return array_column(
            $this->db->query("SELECT tag FROM item_tags WHERE artifact_id = $itemId ORDER BY tag")->fetch_all(MYSQLI_NUM),
            0
        );
    }

    public function test_find_returns_the_owners_item_with_its_type_name(): void
    {
        $item = $this->items->find(10);

        $this->assertSame(10, (int) $item['id']);
        $this->assertSame(1, (int) $item['user_id']);
        $this->assertSame('Catan', $item['Title']);
        $this->assertSame('board-game', $item['type_name']);
    }

    public function test_find_returns_the_items_tags_sorted(): void
    {
        $this->assertSame(['beach-safe', 'family'], $this->items->find(10)['tags']);
    }

    public function test_find_returns_no_tags_for_an_untagged_item(): void
    {
        $id = $this->items->create(['Title' => 'Untagged']);

        $this->assertSame([], $this->items->find($id)['tags']);
    }

    public function test_find_never_returns_another_owners_tags(): void
    {
        // Another owner's tag on the owner's Item, which no write makes.
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (2, 11, 'theirs')");

        $this->assertSame(['family'], $this->items->find(11)['tags']);
        $this->assertSame(['mine'], (new Items($this->db, 2))->find(20)['tags']);
    }

    public function test_find_returns_null_for_another_owners_item_or_a_missing_id(): void
    {
        $this->assertNull($this->items->find(20));
        $this->assertNull($this->items->find(999));
    }

    private function invalid(callable $write): ItemInvalid
    {
        try {
            $write();
        } catch (ItemInvalid $invalid) {
            return $invalid;
        }
        $this->fail('The write must be rejected as invalid.');
    }

    private function itemCount(): int
    {
        return $this->column('SELECT COUNT(*) FROM games')[0];
    }

    public function test_create_applies_the_create_defaults(): void
    {
        $item = $this->items->find($this->items->create(['Title' => 'Quelf']));

        $this->assertSame(1, (int) $item['user_id']);
        $this->assertSame(30, (int) $item['MnT']);
        $this->assertSame(60, (int) $item['MxT']);
        $this->assertSame(1, (int) $item['MnP']);
        $this->assertSame(1, (int) $item['MxP']);
        $this->assertSame('01', $item['SS']);
        $this->assertSame(0, (int) $item['Age']);
        $this->assertSame(app_today(), $item['Acq']);
        $this->assertSame(1, (int) $item['is_kept']);
        $this->assertEquals(120, $item['interaction_frequency_days']);
        $this->assertSame(app_today(), $item['CandidateGroupDate']);
        $this->assertSame('0', $item['UsedRecUserCt']);
        $this->assertNull($item['type_id']);
        $this->assertNull($item['type']);
        $this->assertNull($item['is_digital']);
        $this->assertNull($item['is_physical']);
        $this->assertSame(0, (int) $item['to_get_rid_of']);
    }

    public function test_create_falls_back_to_90_days_when_the_owner_has_no_default_use_interval(): void
    {
        $this->runSql('UPDATE users SET default_use_interval = NULL WHERE id = 1');

        $item = $this->items->find($this->items->create(['Title' => 'Quelf']));

        $this->assertEquals(90, $item['interaction_frequency_days']);
    }

    public function test_create_treats_blank_fields_as_missing(): void
    {
        $item = $this->items->find($this->items->create([
            'Title' => 'Quelf', 'MnT' => '', 'MxT' => '', 'MnP' => '', 'MxP' => '', 'SS' => '',
            'Age' => '', 'Acq' => '', 'is_kept' => '', 'interaction_frequency_days' => '', 'type_id' => '', 'Yr' => '',
        ]));

        $this->assertSame([30, 60, 1, 1], [(int) $item['MnT'], (int) $item['MxT'], (int) $item['MnP'], (int) $item['MxP']]);
        $this->assertSame('01', $item['SS']);
        $this->assertSame(0, (int) $item['Age']);
        $this->assertSame(app_today(), $item['Acq']);
        $this->assertSame(1, (int) $item['is_kept']);
        $this->assertEquals(120, $item['interaction_frequency_days']);
        $this->assertNull($item['type_id']);
        $this->assertNull($item['Yr']);
    }

    public function test_create_dates_a_blank_acquisition_and_the_candidate_group_on_the_app_day(): void
    {
        $zone = date_default_timezone_get();
        try {
            foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tz) {
                date_default_timezone_set($tz);
                $item = $this->items->find($this->items->create(['Title' => 'Quelf', 'Acq' => '']));

                $this->assertSame(app_today(), $item['Acq'], $tz);
                $this->assertSame(app_today(), $item['CandidateGroupDate'], $tz);
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_create_writes_the_given_fields_through_the_normalizers(): void
    {
        $cover = 'https://cf.geekdo-images.com/SfNSwt9FWMx3FHM5ljzicQ__itemrep/img/1jsqDH3ag4F7k82kgg-j_6TOrj4=/fit-in/246x300/filters:strip_icc()/pic200936.jpg';
        $id = $this->items->create([
            'Title' => 'Quelf', 'Notes' => 'Party game', 'Acq' => '2026-09-20', 'type_id' => '2',
            'is_kept' => '0', 'is_in_secondary_collection' => '1', 'is_digital' => '1', 'is_physical' => '0',
            'SS' => '05,06', 'MnT' => '60', 'MxT' => '60', 'MnP' => '3', 'MxP' => '8', 'Age' => '12', 'Yr' => ' 2005 ',
            'interaction_frequency_days' => '182.5', 'image_url' => $cover,
            'bgg_url' => 'boardgamegeek.com/boardgame/19370/quelf', 'bgg_player_votes' => '19',
            'bgg_age_basis' => 'community', 'BGG_Rat' => '6.123', 'tags' => 'Party, family',
        ]);

        $item = $this->items->find($id);
        $this->assertSame('Quelf', $item['Title']);
        $this->assertSame('Party game', $item['Notes']);
        $this->assertSame('2026-09-20', $item['Acq']);
        $this->assertSame(2, (int) $item['type_id']);
        $this->assertSame('film', $item['type']);
        $this->assertSame('film', $item['type_name']);
        $this->assertSame([0, 1, 1, 0], [(int) $item['is_kept'], (int) $item['is_in_secondary_collection'], (int) $item['is_digital'], (int) $item['is_physical']]);
        $this->assertSame('05,06', $item['SS']);
        $this->assertSame([60, 60, 3, 8, 12], [(int) $item['MnT'], (int) $item['MxT'], (int) $item['MnP'], (int) $item['MxP'], (int) $item['Age']]);
        $this->assertEquals(2005, $item['Yr']);
        $this->assertEquals(182.5, $item['interaction_frequency_days']);
        $this->assertSame($cover, $item['image_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/19370/quelf', $item['bgg_url']);
        $this->assertSame(19, (int) $item['bgg_player_votes']);
        $this->assertSame('community', $item['bgg_age_basis']);
        $this->assertSame('6.12', $item['BGG_Rat']);
        $this->assertSame(['family', 'party'], $this->tagsOf($id));
    }

    public function test_create_ignores_unknown_keys_and_never_writes_the_owner_or_snooze(): void
    {
        $item = $this->items->find($this->items->create([
            'Title' => 'Quelf', 'user_id' => 2, 'snoozed_until' => '2030-01-01', 'no_such_column' => 'x',
        ]));

        $this->assertSame(1, (int) $item['user_id']);
        $this->assertNull($item['snoozed_until']);
    }

    public function test_create_rejects_a_type_that_is_not_the_owners(): void
    {
        $before = $this->itemCount();

        $this->assertSame(['Type must be one of your types.'], $this->invalid(fn () => $this->items->create(['Title' => 'Quelf', 'type_id' => 3]))->errors);
        $this->assertSame(['Type must be one of your types.'], $this->invalid(fn () => $this->items->create(['Title' => 'Quelf', 'type_id' => 999]))->errors);
        $this->assertSame(['Type must be one of your types.'], $this->invalid(fn () => $this->items->create(['Title' => 'Quelf', 'type_id' => 'film']))->errors);
        $this->assertSame($before, $this->itemCount());
    }

    public function test_create_reports_every_validation_error_together(): void
    {
        $before = $this->itemCount();

        $invalid = $this->invalid(fn () => $this->items->create([
            'Title' => 'Q', 'is_kept' => 'maybe', 'MnT' => 'long', 'MxP' => 'many', 'MnP' => '6', 'Age' => '-1',
            'Yr' => '20055', 'Acq' => '2026-02-30', 'bgg_url' => 'https://example.com/quelf',
            'interaction_frequency_days' => '0', 'tags' => 'party',
        ]));

        $expected = [
            'Title must be between 2 and 255 characters.',
            'Kept must be true or false.',
            'Minimum Time must be a number.',
            'Maximum User Count must be a number.',
            'Minimum Age must be a non-negative number.',
            'Year must be a 1 to 4 digit number.',
            'Tracking Start Date must be a valid date (YYYY-MM-DD).',
            'BoardGameGeek Link must be a boardgamegeek.com, rpggeek.com, or videogamegeek.com page.',
            'Interaction Frequency must be a positive number.',
        ];
        $this->assertInstanceOf(\InvalidArgumentException::class, $invalid);
        $this->assertSame($expected, $invalid->errors);
        $this->assertSame(implode(' ', $expected), $invalid->getMessage());
        $this->assertSame($before, $this->itemCount());
        $this->assertSame(0, $this->column("SELECT COUNT(*) FROM item_tags WHERE tag = 'party'")[0]);
    }

    public function test_create_rejects_a_blank_title_and_unordered_ranges(): void
    {
        $this->assertSame(['Title cannot be blank.'], $this->invalid(fn () => $this->items->create([]))->errors);
        $this->assertSame(
            ['Minimum Time cannot exceed Maximum Time.', 'Minimum User Count cannot exceed Maximum User Count.'],
            $this->invalid(fn () => $this->items->create(['Title' => 'Quelf', 'MnT' => '90', 'MnP' => '4']))->errors
        );
    }

    public function test_a_failed_create_leaves_no_item_and_no_tags(): void
    {
        $before = $this->itemCount();
        $this->runSql('CREATE TRIGGER item_tags_no_insert BEFORE INSERT ON item_tags FOR EACH ROW
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'no tags\'');

        try {
            $this->items->create(['Title' => 'Quelf', 'tags' => 'party']);
            $this->fail('The create must fail.');
        } catch (\mysqli_sql_exception $expected) {
        }

        $this->assertSame($before, $this->itemCount());
    }

    public function test_update_changes_only_the_given_fields(): void
    {
        $before = $this->items->find(10);

        $this->items->update(10, ['Notes' => 'Seafarers expansion']);

        $after = $this->items->find(10);
        $this->assertSame('Seafarers expansion', $after['Notes']);
        unset($before['Notes'], $after['Notes']);
        $this->assertSame($before, $after);
        $this->assertSame(['beach-safe', 'family'], $this->tagsOf(10));
    }

    public function test_update_leaves_the_format_flags_and_legacy_fields_alone(): void
    {
        $this->items->update(10, [
            'Title' => 'Catan', 'is_kept' => '0', 'to_get_rid_of' => '1', 'is_in_secondary_collection' => '1',
            'CandidateGroupDate' => '2030-01-01', 'UsedRecUserCt' => '99',
        ]);

        $item = $this->items->find(10);
        $this->assertSame([0, 1, 1], [(int) $item['is_kept'], (int) $item['to_get_rid_of'], (int) $item['is_in_secondary_collection']]);
        $this->assertSame([1, 1], [(int) $item['is_digital'], (int) $item['is_physical']]);
        $this->assertSame('yes', $item['Candidate']);
        $this->assertSame('2020-05-01', $item['CandidateGroupDate']);
        $this->assertSame('3', $item['UsedRecUserCt']);
        $this->assertSame('https://cf.geekdo-images.com/catan.jpg', $item['image_url']);
    }

    public function test_update_writes_the_format_flags_when_given(): void
    {
        $this->items->update(10, ['is_digital' => '0', 'is_physical' => '']);

        $item = $this->items->find(10);
        $this->assertSame(0, (int) $item['is_digital']);
        $this->assertNull($item['is_physical']);
    }

    public function test_update_gives_blank_times_counts_sweet_spot_age_and_acquisition_their_create_defaults(): void
    {
        $this->items->update(10, ['MnT' => '', 'MxT' => '', 'MnP' => '', 'MxP' => '', 'SS' => '', 'Age' => '', 'Acq' => '']);

        $item = $this->items->find(10);
        $this->assertSame([30, 60, 1, 1], [(int) $item['MnT'], (int) $item['MxT'], (int) $item['MnP'], (int) $item['MxP']]);
        $this->assertSame('01', $item['SS']);
        $this->assertSame(0, (int) $item['Age']);
        $this->assertSame(app_today(), $item['Acq']);
    }

    public function test_update_with_a_blank_type_keeps_the_current_type(): void
    {
        $this->items->update(10, ['type_id' => '']);

        $this->assertSame('board-game', $this->items->find(10)['type']);

        $this->items->update(10, ['type_id' => '2']);

        $item = $this->items->find(10);
        $this->assertSame(2, (int) $item['type_id']);
        $this->assertSame('film', $item['type']);
    }

    public function test_update_rejects_a_type_that_is_not_the_owners(): void
    {
        $invalid = $this->invalid(fn () => $this->items->update(10, ['type_id' => 3]));

        $this->assertSame(['Type must be one of your types.'], $invalid->errors);
        $this->assertSame(1, (int) $this->items->find(10)['type_id']);
    }

    public function test_update_replaces_the_tags_only_when_given(): void
    {
        $this->items->update(10, ['tags' => ['Outdoor', 'family']]);
        $this->assertSame(['family', 'outdoor'], $this->tagsOf(10));

        $this->items->update(10, ['Title' => 'Catan']);
        $this->assertSame(['family', 'outdoor'], $this->tagsOf(10));

        $this->items->update(10, ['tags' => '']);
        $this->assertSame([], $this->tagsOf(10));
    }

    public function test_update_stores_the_year(): void
    {
        $this->items->update(10, ['Yr' => '1995']);
        $this->assertEquals(1995, $this->items->find(10)['Yr']);

        $this->items->update(10, ['Yr' => ' ']);
        $this->assertNull($this->items->find(10)['Yr']);
    }

    public function test_update_stores_the_bgg_link_and_its_vote_basis(): void
    {
        $link = 'https://boardgamegeek.com/boardgame/13/catan';
        $this->items->update(10, ['bgg_url' => 'http://boardgamegeek.com/boardgame/13/catan', 'bgg_player_votes' => '19', 'bgg_age_basis' => 'community']);
        $item = $this->items->find(10);
        $this->assertSame($link, $item['bgg_url']);
        $this->assertSame(19, (int) $item['bgg_player_votes']);

        $this->assertSame(
            ['BoardGameGeek Link must be a boardgamegeek.com, rpggeek.com, or videogamegeek.com page.'],
            $this->invalid(fn () => $this->items->update(10, ['bgg_url' => 'javascript:alert(1)']))->errors
        );
        $this->assertSame($link, $this->items->find(10)['bgg_url']);

        $this->items->update(10, ['bgg_player_votes' => 'many', 'bgg_age_basis' => 'guess']);
        $item = $this->items->find(10);
        $this->assertSame($link, $item['bgg_url']);
        $this->assertNull($item['bgg_player_votes']);
        $this->assertNull($item['bgg_age_basis']);

        $this->items->update(10, ['bgg_player_votes' => '19', 'bgg_age_basis' => 'community']);
        $this->items->update(10, ['bgg_url' => '']);
        $item = $this->items->find(10);
        $this->assertNull($item['bgg_url']);
        $this->assertNull($item['bgg_player_votes']);
        $this->assertNull($item['bgg_age_basis']);
    }

    public function test_update_never_writes_the_id_owner_or_snooze(): void
    {
        $this->items->update(10, ['id' => 99, 'user_id' => 2, 'snoozed_until' => '2030-01-01']);

        $item = $this->items->find(10);
        $this->assertSame(1, (int) $item['user_id']);
        $this->assertNull($item['snoozed_until']);
    }

    public function test_updating_another_owners_item_is_not_found_and_changes_nothing(): void
    {
        try {
            $this->items->update(20, ['Title' => 'Mine now', 'tags' => 'stolen']);
            $this->fail('Another owner\'s Item must not be updated.');
        } catch (\OutOfBoundsException $expected) {
        }

        $this->assertSame('Private item', (new Items($this->db, 2))->find(20)['Title']);
        $this->assertSame(['mine'], $this->tagsOf(20));
    }

    public function test_updating_a_missing_item_is_not_found(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->items->update(999, ['Title' => 'Nothing']);
    }

    public function test_an_invalid_update_leaves_the_item_and_its_tags(): void
    {
        $invalid = $this->invalid(fn () => $this->items->update(10, ['Title' => 'C', 'MnT' => '500', 'tags' => 'new']));

        $this->assertSame(['Title must be between 2 and 255 characters.', 'Minimum Time cannot exceed Maximum Time.'], $invalid->errors);
        $this->assertSame('Catan', $this->items->find(10)['Title']);
        $this->assertSame(60, (int) $this->items->find(10)['MnT']);
        $this->assertSame(['beach-safe', 'family'], $this->tagsOf(10));
    }

    public function test_a_failed_update_leaves_the_item_and_its_tags(): void
    {
        $this->runSql('CREATE TRIGGER item_tags_no_insert BEFORE INSERT ON item_tags FOR EACH ROW
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'no tags\'');

        try {
            $this->items->update(10, ['Title' => 'Catan Junior', 'tags' => 'kids']);
            $this->fail('The update must fail.');
        } catch (\mysqli_sql_exception $expected) {
        }

        $this->assertSame('Catan', $this->items->find(10)['Title']);
        $this->assertSame(['beach-safe', 'family'], $this->tagsOf(10));
    }

    public function test_set_kept_writes_the_owners_kept_flag(): void
    {
        $this->items->setKept(10, false);
        $this->assertSame(0, (int) $this->items->find(10)['is_kept']);

        $this->items->setKept(10, true);
        $this->assertSame(1, (int) $this->items->find(10)['is_kept']);
    }

    public function test_set_to_get_rid_of_writes_the_owners_flag(): void
    {
        $this->items->setToGetRidOf(10, true);
        $this->assertSame(1, (int) $this->items->find(10)['to_get_rid_of']);

        $this->items->setToGetRidOf(10, false);
        $this->assertSame(0, (int) $this->items->find(10)['to_get_rid_of']);
    }

    public function test_snooze_sets_and_returns_today_plus_the_days(): void
    {
        $until = (new \DateTime('today'))->modify('+5 days')->format('Y-m-d');

        $this->assertSame($until, $this->items->snooze(10, 5));
        $this->assertSame($until, $this->items->find(10)['snoozed_until']);
    }

    public function test_a_snooze_below_one_day_counts_as_one(): void
    {
        $tomorrow = (new \DateTime('today'))->modify('+1 day')->format('Y-m-d');

        $this->assertSame($tomorrow, $this->items->snooze(10, 0));
        $this->assertSame($tomorrow, $this->items->find(10)['snoozed_until']);
    }

    public function test_a_flag_write_changes_only_its_own_field(): void
    {
        $before = $this->items->find(10);

        $this->items->setKept(10, false);
        $this->items->setToGetRidOf(10, true);
        $until = $this->items->snooze(10, 3);

        $after = $this->items->find(10);
        $this->assertSame(
            array_replace($before, ['is_kept' => 0, 'to_get_rid_of' => 1, 'snoozed_until' => $until]),
            $after
        );
    }

    public function test_flag_writes_succeed_on_an_item_that_breaks_an_item_rule(): void
    {
        $this->runSql("UPDATE games SET Title = 'C', MnT = 500 WHERE id = 10");

        $this->items->setKept(10, false);
        $this->items->setToGetRidOf(10, true);
        $this->items->snooze(10, 2);

        $item = $this->items->find(10);
        $this->assertSame(0, (int) $item['is_kept']);
        $this->assertSame(1, (int) $item['to_get_rid_of']);
        $this->assertNotNull($item['snoozed_until']);
        $this->assertSame('C', $item['Title']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flagWrites')]
    public function test_a_flag_write_on_another_owners_item_is_not_found_and_changes_nothing(callable $write): void
    {
        $before = (new Items($this->db, 2))->find(20);

        try {
            $write($this->items, 20);
            $this->fail('Another owner\'s Item must not be changed.');
        } catch (\OutOfBoundsException $expected) {
        }

        $this->assertSame($before, (new Items($this->db, 2))->find(20));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flagWrites')]
    public function test_a_flag_write_on_a_missing_item_is_not_found(callable $write): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $write($this->items, 999);
    }

    public static function flagWrites(): array
    {
        return [
            'kept' => [fn (Items $items, int $id) => $items->setKept($id, false)],
            'to get rid of' => [fn (Items $items, int $id) => $items->setToGetRidOf($id, true)],
            'snooze' => [fn (Items $items, int $id) => $items->snooze($id, 7)],
        ];
    }
}

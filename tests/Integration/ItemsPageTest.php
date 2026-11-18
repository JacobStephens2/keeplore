<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalPhpServer;

require_once __DIR__ . '/../Support/LocalPhpServer.php';

/**
 * Items page requests cross metadata/data with raw HTTP inputs and real
 * storage, including the later serialized GET and its different Type defaults.
 */
final class ItemsPageTest extends TestCase
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
        $this->db->query('ALTER TABLE users ADD COLUMN bgg_username VARCHAR(64) NULL');
        $this->runSql('DROP TABLE games, types');
        $this->runSql($this->schemaTable('types') . $this->schemaTable('games'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-tags.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings.sql'));
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-item-bgg-ratings-manual.sql'));
        $this->runSql("INSERT INTO types (id, objectType, user_id) VALUES
            (1, 'board-game', 1), (2, 'film', 1), (3, 'card game', 2)");
        $this->runSql("INSERT INTO games (id, user_id, Title, type_id, type, is_kept, is_in_secondary_collection,
                Acq, interaction_frequency_days, SS, Age)
            VALUES
            (10, 1, 'Catan', 1, 'board-game', 1, 0, '2020-01-01', NULL, '03,04', 10),
            (11, 1, 'Azul', 1, 'board-game', 1, 0, '2021-01-01', NULL, '02', 8),
            (12, 1, 'Arrival', 2, 'film', 0, 1, '2022-01-01', NULL, NULL, NULL),
            (13, 1, 'Brass', NULL, 'legacy', 1, 0, '2022-01-01', 30, '03', 14),
            (15, 1, 'Hanabi', 1, 'board-game', 1, 0, '2022-01-01', NULL, NULL, NULL),
            (14, 1, 'Coup', 1, 'board-game', NULL, 0, NULL, NULL, NULL, NULL),
            (20, 2, 'Bohnanza', 3, 'card game', 1, 1, '2025-01-01', NULL, '03', 8)");
        $this->runSql("INSERT INTO item_tags (user_id, artifact_id, tag) VALUES
            (1, 10, 'beach-safe'), (1, 12, 'family'), (2, 20, 'beach-safe')");
        $this->runSql('DELETE FROM uses');
        $this->runSql("INSERT INTO uses (artifact_id, user_id, use_date) VALUES
            (10, 1, '2026-01-01'), (10, 1, '2026-02-01'), (13, 1, '2026-01-01')");
        $this->runSql("INSERT INTO responses (Title, user_id, PlayDate) VALUES (11, 1, '2026-03-01'), (10, 1, '2025-12-01')");
        require_once PRIVATE_PATH . '/classes/Items.php';
        require_once PRIVATE_PATH . '/items_list.php';
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

    private function page(int $owner = 1, string $today = '2026-06-01'): \ItemsPage
    {
        return new \ItemsPage($this->db, $owner, $today);
    }

    private function rows(array $get = []): array
    {
        return $this->page()->data($get)['items'];
    }

    private function ids(array $get = []): array
    {
        return array_column($this->rows($get), 'id');
    }

    private function row(int $id): array
    {
        return array_column($this->rows(['type' => ['']]), null, 'id')[$id];
    }

    public function test_rows_are_newest_acquisition_first_then_kept_first_then_id(): void
    {
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['type' => ['']]));
    }

    public function test_metadata_query_round_trip_uses_owner_types_and_excludes_untyped_items(): void
    {
        $page = new \ItemsPage($this->db, 1, '2026-06-01');
        $metadata = $page->metadata('GET', [], []);
        $this->assertSame(['types' => ['board-game' => 1, 'film' => 2], 'selected' => ['1', '2']], $metadata['type_filter']);
        $this->assertSame([], $metadata['data_query']);
        parse_str(http_build_query($metadata['data_query']), $query);
        $answer = (new \ItemsPage($this->db, 1, '2026-06-01'))->data($query);
        $this->assertSame([15, 12, 11, 10, 14], array_column($answer['items'], 'id'));
        $this->assertSame(5, $answer['count']);
    }

    public function test_blank_or_absent_post_types_change_on_the_second_request(): void
    {
        foreach ([['GET', ['type' => ['']], [], []], ['POST', [], [], []], ['pOsT', [], ['type' => '1'], ['']]] as [$method, $get, $post, $initial]) {
            $metadata = $this->page()->metadata($method, $get, $post);
            $this->assertSame($initial, $metadata['filters']['type']);
            $this->assertSame([], $metadata['type_filter']['selected']);
            $this->assertFalse($metadata['type_filter_active']);
            $this->assertSame([], $metadata['data_query']);
            parse_str(http_build_query($metadata['data_query']), $query);
            $this->assertSame([15, 12, 11, 10, 14], $this->ids($query));
        }
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['type' => ['']]));
    }

    public function test_owner_without_types_can_see_untyped_items_after_serialization(): void
    {
        $this->db->query('DELETE FROM types WHERE user_id = 1');
        foreach (['GET', 'POST'] as $method) {
            $metadata = $this->page()->metadata($method, [], []);
            $this->assertSame(['types' => [], 'selected' => []], $metadata['type_filter']);
            $this->assertSame(['all'], array_keys($metadata['type_switch']['options']));
            parse_str(http_build_query($metadata['data_query']), $query);
            $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids($query));
        }
    }

    public function test_mixed_sources_preserve_method_specific_kept_and_type_and_post_first_other_fields(): void
    {
        $get = ['kept' => 'no', 'type' => ['2'], 'interval' => '30', 'players' => '3', 'age' => '10', 'tag' => 'ignored'];
        $post = ['kept' => 'yes', 'type' => ['1'], 'interval' => '14', 'players' => ' 4 ', 'age' => '8',
            'age_unknown' => 'yes', 'showAttributes' => 'yes', 'tag' => ' Beach-Safe '];
        $metadata = $this->page()->metadata('GET', $get, $post);
        $this->assertSame([
            'kept' => 'no', 'type' => ['2'], 'interval' => 14, 'players' => 4, 'age' => 8,
            'ageUnknown' => true, 'showAttributes' => 'yes', 'tagFilter' => ' Beach-Safe ',
        ], $metadata['filters']);
        $metadata = $this->page()->metadata('pOsT', $get, $post);
        $this->assertSame('yes', $metadata['filters']['kept']);
        $this->assertSame(['1'], $metadata['filters']['type']);
        $this->assertSame(' Beach-Safe ', $metadata['data_query']['tag']);
        $this->assertSame(['kept' => 'yes', 'type' => ['1'], 'interval' => 14, 'players' => 4,
            'age' => 8, 'age_unknown' => 'yes', 'showAttributes' => 'yes', 'tag' => ' Beach-Safe '], $metadata['data_query']);
        parse_str(http_build_query($metadata['data_query']), $query);
        $this->assertSame([], $this->ids($query));
        $metadata = $this->page()->metadata('POST', ['kept' => 'yes', 'type' => ['2']], []);
        $this->assertSame('allkeptandnot', $metadata['filters']['kept']);
        $this->assertSame([], $metadata['filters']['type']);
        $this->assertSame([], $metadata['data_query']);
    }

    public function test_player_precedence_legacy_names_and_positive_integer_spellings(): void
    {
        foreach ([
            [['players' => '3'], ['sweetSpotFilter' => '4'], 3],
            [['sweetSpotFilter' => '3'], ['sweetSpotFilter' => '4'], 4],
            [['sweetSpotFilter' => '3'], [], 3],
            [['players' => '3'], ['players' => ' 4 '], 4],
        ] as [$get, $post, $expected]) {
            $metadata = $this->page()->metadata('GET', $get, $post);
            $this->assertSame($expected, $metadata['filters']['players']);
            $this->assertSame(['players' => $expected], $metadata['data_query']);
        }
        foreach (['0', '-1', '03', '1.5', '1e1', '', 'three', 3, ['3']] as $invalid) {
            $metadata = $this->page()->metadata('GET', ['players' => $invalid, 'age' => $invalid, 'age_unknown' => 'yes'], []);
            $this->assertNull($metadata['filters']['players']);
            $this->assertNull($metadata['filters']['age']);
            $this->assertTrue($metadata['filters']['ageUnknown']);
            $this->assertSame([], $metadata['data_query']);
        }
        $metadata = $this->page()->metadata('GET', ['age' => ' 10 '], []);
        $this->assertSame(10, $metadata['filters']['age']);
        $this->assertSame([11, 10], $this->ids(['age' => ' 10 ']));
    }

    public function test_intervals_keep_numeric_conversion_and_owner_defaults_are_read_per_operation(): void
    {
        $page = $this->page();
        $this->db->query('UPDATE users SET default_use_interval = 20 WHERE id = 1');
        $this->db->query('UPDATE users SET default_use_interval = 7 WHERE id = 2');
        $this->assertSame(20.0, $page->metadata('GET', [], [])['filters']['interval']);
        $this->assertSame(7.0, $this->page(2)->metadata('GET', [], [])['filters']['interval']);
        $this->db->query('UPDATE users SET default_use_interval = 10 WHERE id = 1');
        $rows = array_column($page->data(['type' => ['']])['items'], null, 'id');
        $this->assertSame('2026-02-21', $rows[10]['use_by']);
        $this->assertSame('2026-03-02', $rows[13]['use_by']);
        foreach (['0' => 0, '-3' => -3, '0.5' => 0.5, '1e1' => 10.0, 'nonsense' => 10.0] as $raw => $expected) {
            $metadata = $page->metadata('GET', ['interval' => (string) $raw], []);
            $this->assertSame($expected, $metadata['filters']['interval']);
            $query = $metadata['data_query'];
            $this->assertSame((string) $expected === '10' ? [] : ['interval' => $expected], $query);
            parse_str(http_build_query($query), $transport);
            $this->assertSame(json_encode($page->data(['interval' => (string) $expected])), json_encode($page->data($transport)));
        }
    }

    public function test_navigation_queries_active_states_and_carried_fields_are_complete(): void
    {
        $this->db->query("UPDATE types SET objectType = 'board game' WHERE id = 1");
        $this->db->query("INSERT INTO types (id, objectType, user_id) VALUES (4, 'other', 1)");
        $metadata = $this->page()->metadata('GET', [
            'kept' => 'secondary_only', 'type' => ['2', '1'], 'interval' => '14',
            'players' => '4', 'age' => '6', 'age_unknown' => 'yes', 'showAttributes' => 'yes', 'tag' => 'Beach-Safe',
        ], []);
        $base = ['kept' => 'secondary_only', 'type' => ['2', '1'], 'interval' => 14,
            'players' => 4, 'age' => 6, 'age_unknown' => 'yes', 'showAttributes' => 'yes', 'tag' => 'Beach-Safe'];
        $this->assertSame($base, $metadata['data_query']);
        $this->assertSame(['1', '2'], $metadata['type_filter']['selected']);
        $this->assertTrue($metadata['type_filter_active']);
        $this->assertNull($metadata['kept_switch']['active']);
        foreach (['all' => 'All', 'yes' => 'Kept', 'no' => 'Not kept'] as $key => $label) {
            $query = $base;
            if ($key === 'all') { unset($query['kept']); } else { $query['kept'] = $key; }
            $this->assertSame(['label' => $label, 'active' => false, 'query' => $query], $metadata['kept_switch']['options'][$key]);
        }
        $this->assertSame(['all', 'games', 'other'], array_keys($metadata['type_switch']['options']));
        $this->assertNull($metadata['type_switch']['active']);
        foreach (['all' => null, 'games' => ['1'], 'other' => ['4']] as $key => $types) {
            $query = $base;
            if ($types === null) { unset($query['type']); } else { $query['type'] = $types; }
            $this->assertSame($query, $metadata['type_switch']['options'][$key]['query']);
            $this->assertFalse($metadata['type_switch']['options'][$key]['active']);
        }
        $this->assertSame(['kept' => 'secondary_only', 'type' => ['2', '1'], 'interval' => 14,
            'showAttributes' => 'yes', 'tag' => 'Beach-Safe'], $metadata['picker']['clear_query']);
        $this->assertSame([['kept', 'secondary_only'], ['type[0]', '2'], ['type[1]', '1'], ['interval', '14'],
            ['showAttributes', 'yes'], ['tag', 'Beach-Safe']], $metadata['picker']['hidden_fields']);
        $this->assertSame([['interval', '14'], ['players', '4'], ['age', '6'], ['age_unknown', 'yes']], $metadata['filter_panel_hidden_fields']);
        foreach ([['kept' => 'all', 'type' => ['1', '2', '4']], ['kept' => 'yes', 'type' => ['1']], ['kept' => 'no', 'type' => ['4']]] as $get) {
            $answer = $this->page()->metadata('GET', $get, []);
            $this->assertTrue($answer['kept_switch']['options'][$get['kept']]['active']);
            $active = count($get['type']) === 3 ? 'all' : ($get['type'] === ['1'] ? 'games' : 'other');
            $this->assertTrue($answer['type_switch']['options'][$active]['active']);
        }
    }

    public function test_metadata_provides_headings_empty_messages_and_columns_with_owner_reviewers(): void
    {
        $this->db->query("UPDATE users SET bgg_username = 'Gyges' WHERE id = 1");
        $this->db->query("UPDATE users SET bgg_username = 'Foreign' WHERE id = 2");
        $default = $this->page()->metadata('GET', [], []);
        $this->assertNull($default['heading']);
        $this->assertSame('Items', $default['page_title']);
        $this->assertSame('No items yet.', $default['empty_message']);
        $this->assertSame(['is_kept', 'title', 'bgg_average', 'bgg_rating:Gyges', 'tags', 'most_recent_use', 'type', 'acq', 'use_by'], array_column($default['columns'], 'key'));
        foreach ([['players' => '1'], ['age' => '7'], ['players' => '3', 'age' => '7']] as $i => $get) {
            $metadata = $this->page()->metadata('GET', $get + ['kept' => 'secondary_only'], []);
            $heading = ['Best at 1 player', 'Suitable for age 7', 'Best at 3 players, suitable for age 7'][$i];
            $this->assertSame($heading, $metadata['heading']);
            $this->assertSame($heading . ' (Secondary Only)', $metadata['page_title']);
            $this->assertSame('No items are ' . lcfirst($heading) . '.', $metadata['empty_message']);
            $this->assertContains('players', array_column($metadata['columns'], 'key'));
            $this->assertContains('time', array_column($metadata['columns'], 'key'));
        }
        $metadata = $this->page()->metadata('GET', ['showAttributes' => 'yes'], []);
        $this->assertSame(['is_kept', 'title', 'age', 'players', 'time', 'bgg_average', 'bgg_rating:Gyges', 'tags',
            'most_recent_use', 'type', 'acq', 'use_by', 'avg_time', 'candidate'], array_column($metadata['columns'], 'key'));
        $this->assertSame(['key' => 'title', 'label' => 'Name', 'tooltip' => ''], $metadata['columns'][1]);
        $this->assertSame('Recommended minimum age', $metadata['columns'][2]['tooltip']);
    }

    public function test_metadata_succeeds_without_use_history_but_data_still_reads_it(): void
    {
        $this->db->query('DROP TABLE uses, responses');
        $this->assertSame('Items', $this->page()->metadata('GET', [], [])['page_title']);
        try {
            $this->page()->data([]);
            $this->fail('Data must still read real Use history.');
        } catch (\mysqli_sql_exception $error) {
            $this->assertStringContainsString("doesn't exist", $error->getMessage());
        }
    }

    public function test_data_needs_no_reviewer_name_column_but_storage_failures_propagate(): void
    {
        $this->db->query('ALTER TABLE users DROP COLUMN bgg_username');
        $this->assertSame([15, 12, 11, 10, 14], $this->ids());
        $this->expectException(\mysqli_sql_exception::class);
        $this->page()->metadata('GET', [], []);
    }

    public function test_trusted_owner_scopes_types_rows_tags_and_ratings_despite_request_owner_fields(): void
    {
        $metadata = $this->page(2)->metadata('GET', ['user_id' => '1', 'owner' => '1'], ['user_id' => '1']);
        $this->assertSame(['types' => ['card game' => 3], 'selected' => ['3']], $metadata['type_filter']);
        $answer = $this->page(2)->data(['user_id' => '1', 'owner' => '1']);
        $this->assertSame([20], array_column($answer['items'], 'id'));
        $this->assertSame(['beach-safe'], $answer['items'][0]['tags']);
        $this->assertSame([], $this->ids(['type' => ['3'], 'user_id' => '2']));
    }

    public function test_data_preserves_every_row_field_and_json_container_shape(): void
    {
        $this->db->query("UPDATE games SET MnP = 2, MxP = 4, MnT = 45, MxT = 75, Candidate = 'family', BGG_Rat = '8.00' WHERE id = 10");
        $row = $this->row(10);
        $ratings = $row['bgg_ratings'];
        $this->assertInstanceOf(\stdClass::class, $ratings);
        unset($row['bgg_ratings']);
        $this->assertSame([
            'id' => 10, 'title' => 'Catan', 'type' => 'board-game', 'tags' => ['beach-safe'], 'is_kept' => true,
            'acq' => '2020-01-01', 'most_recent_use' => '2026-02-01', 'use_by' => '2026-07-31', 'use_by_overdue' => false,
            'ss' => '03,04', 'players' => '2–4 (best 3, 4)', 'players_sort' => 2, 'age' => '10+', 'age_sort' => 10,
            'copy_text' => 'Catan, 2–4 (3, 4), 10 yrs', 'time' => '45–75 min', 'time_sort' => 45,
            'avg_time' => 60, 'candidate' => true, 'bgg_average' => '8', 'bgg_average_sort' => 8.0,
        ], $row);
        $this->assertSame('{}', json_encode($ratings));
        $empty = $this->page()->data(['type' => ['999']]);
        $this->assertSame('{"items":[],"count":0}', json_encode($empty));
        $this->assertSame([], $this->row(14)['tags']);
        $this->assertSame('', $this->row(14)['use_by']);
    }

    public function test_data_presents_real_ratings_scores_comments_sites_and_owner_isolation(): void
    {
        $this->db->query("UPDATE games SET bgg_url = 'https://rpggeek.com/rpgitem/161315/ravine' WHERE id = 10");
        $this->db->query("INSERT INTO item_bgg_ratings (user_id, artifact_id, bgg_username, rating, comment, is_manual) VALUES
            (1, 10, 'Gyges', 8, 'Fine.', 0), (1, 10, 'Ada', NULL, 'Just a note.', 1),
            (2, 10, 'Foreign', 9, 'Wrong owner', 0), (2, 20, 'Foreign', 9, 'Theirs', 0)");
        $ratings = $this->row(10)['bgg_ratings'];
        $this->assertSame(['Ada', 'Gyges'], array_keys($ratings));
        $this->assertSame([
            'rating' => 8.0, 'comment' => 'Fine.', 'url' => 'https://rpggeek.com/rpgitem/161315/ravine',
            'manual' => false, 'score_text' => '8', 'sort' => 8.0, 'site_name' => 'RPGGeek',
        ], $ratings['Gyges']);
        $this->assertSame(['rating' => null, 'comment' => 'Just a note.', 'url' => 'https://rpggeek.com/rpgitem/161315/ravine',
            'manual' => true, 'sort' => 0, 'site_name' => 'RPGGeek'], $ratings['Ada']);
        $this->db->query("UPDATE games SET bgg_url = NULL WHERE id = 10");
        $this->assertSame('BoardGameGeek', $this->row(10)['bgg_ratings']['Gyges']['site_name']);
        $this->db->query("UPDATE item_bgg_ratings SET rating = 9.5 WHERE user_id = 1 AND bgg_username = 'Gyges'");
        $this->assertSame('9.5', $this->row(10)['bgg_ratings']['Gyges']['score_text']);
        $this->assertSame(['bgg_rating:Ada', 'bgg_rating:Gyges'], array_values(array_filter(
            array_column($this->page()->metadata('GET', [], [])['columns'], 'key'), fn ($key) => str_starts_with($key, 'bgg_rating:')
        )));
    }

    public static function rowFacts(): array
    {
        return [
            [2, 4, '02', 30, 60, 8, '2–4 (best 2)', 2, '30–60 min', 30, '8+', 8],
            [null, null, '06-8', null, null, null, 'best 6–8', 6, '', null, '', null],
            [null, 6, '', null, 90, 0, '6', 6, '90 min', 90, '', null],
            [5, 2, '03', 60, 30, 10, '5–2 (best 3)', 2, '60–30 min', 30, '10+', 10],
            [null, null, '', 45, 45, null, '', null, '45 min', 45, '', null],
        ];
    }

    #[DataProvider('rowFacts')]
    public function test_row_labels_and_sort_values_come_from_stored_item_facts($low, $high, $ss, $minTime, $maxTime, $age, $players, $playersSort, $time, $timeSort, $ageLabel, $ageSort): void
    {
        $stmt = $this->db->prepare('UPDATE games SET MnP = ?, MxP = ?, SS = ?, MnT = ?, MxT = ?, Age = ? WHERE id = 10');
        $stmt->bind_param('iisiii', $low, $high, $ss, $minTime, $maxTime, $age);
        $stmt->execute();
        $stmt->close();
        $row = $this->row(10);
        $this->assertSame([$players, $playersSort, $time, $timeSort, $ageLabel, $ageSort],
            [$row['players'], $row['players_sort'], $row['time'], $row['time_sort'], $row['age'], $row['age_sort']]);
    }

    public function test_app_day_is_independent_of_server_zone_and_due_today_is_not_overdue(): void
    {
        $this->db->query("UPDATE games SET interaction_frequency_days = NULL, Acq = '2026-07-30' WHERE id = 15");
        $zone = date_default_timezone_get();
        try {
            foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $tz) {
                date_default_timezone_set($tz);
                $today = app_today();
                $this->db->query("UPDATE games SET Acq = '$today' WHERE id = 15");
                $rows = array_column((new \ItemsPage($this->db, 1))->data(['interval' => '0'])['items'], null, 'id');
                $this->assertSame($today, $rows[15]['use_by']);
                $this->assertFalse($rows[15]['use_by_overdue']);
            }
        } finally {
            date_default_timezone_set($zone);
        }
        $rows = array_column($this->page(1, '2026-07-31')->data([])['items'], null, 'id');
        $this->assertFalse($rows[10]['use_by_overdue']);
        $rows = array_column($this->page(1, '2026-08-01')->data([])['items'], null, 'id');
        $this->assertTrue($rows[10]['use_by_overdue']);
        $this->assertFalse($rows[12]['use_by_overdue']);
    }

    public function test_http_adapters_emit_metadata_and_the_existing_json_shape_and_headers(): void
    {
        // Copy the real adapters beside an isolated bootstrap. Request setup
        // supplies a trusted guest and this test's disposable database only.
        $root = sys_get_temp_dir() . '/keeplore-items-http-' . bin2hex(random_bytes(6));
        mkdir($root . '/ui/artifacts', 0777, true);
        mkdir($root . '/private');
        $server = null;
        try {
            $credentials = var_export([
                getenv('KEEPLORE_TEST_DB_HOST'), getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
                getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '', $this->databaseName,
                (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306),
            ], true);
            $bootstrap = '<?php require_once ' . var_export(PROJECT_PATH . '/tests/bootstrap.php', true) . ';'
                . 'require_once ' . var_export(PRIVATE_PATH . '/auth_functions.php', true) . ';'
                . 'define("API_ORIGIN", "127.0.0.1");'
                . '$_SESSION = ["user_id" => 1, "guest_mode" => true];'
                . '$db = new mysqli(...' . $credentials . ');';
            file_put_contents($root . '/private/initialize.php', $bootstrap);
            symlink(PRIVATE_PATH . '/items_list.php', $root . '/private/items_list.php');
            foreach (['index.php', 'items-data.php'] as $file) {
                copy(PROJECT_PATH . '/ui/artifacts/' . $file, $root . '/ui/artifacts/' . $file);
            }
            $server = new LocalPhpServer('<?php chdir(' . var_export($root . '/ui/artifacts', true) . ');'
                . 'require basename(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));');
            $get = ['players' => '3', 'age' => '10', 'age_unknown' => 'yes', 'type' => ['1']];
            $metadata = $this->page()->metadata('GET', $get, []);
            $html = $server->request('/artifacts/index.php?' . http_build_query($get));
            $this->assertSame(200, $html['status']);
            $this->assertStringContainsString('id="items-search"', $html['body']);
            $this->assertStringContainsString('Loading items…', $html['body']);
            $this->assertStringContainsString('name="csrf_token"', $html['body']);
            preg_match('/<script type="application\/json" id="items-list-config">(.*?)<\/script>/s', $html['body'], $match);
            $config = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(array_column($metadata['columns'], 'key'), $config['columns']);
            preg_match_all('/<th data-sort="([^"]+)"/', $html['body'], $headers);
            $this->assertSame($config['columns'], $headers[1]);
            $this->assertSame('/artifacts/items-data.php?' . http_build_query($metadata['data_query']), $config['dataUrl']);
            $this->assertSame($metadata['empty_message'], $config['emptyMessage']);
            $this->assertTrue($config['isGuest']);
            $this->assertSame('/artifacts/show.php?id=', $config['itemUrlPrefix']);
            $this->assertNotEmpty($config['csrfToken']);
            $data = $server->request($config['dataUrl']);
            $this->assertSame(200, $data['status']);
            $this->assertStringContainsString('Content-Type: application/json', $data['headers']);
            $this->assertStringContainsString('Cache-Control: no-store', $data['headers']);
            $body = json_decode($data['body'], false, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['items', 'count'], array_keys(get_object_vars($body)));
            $this->assertSame(1, $body->count);
            $this->assertSame(10, $body->items[0]->id);
            $this->assertInstanceOf(\stdClass::class, $body->items[0]->bgg_ratings);
            $this->db->query('DROP TABLE uses, responses');
            $this->assertStringContainsString('Loading items…', $server->request('/artifacts/index.php')['body']);
        } finally {
            $server?->stop();
            foreach (['ui/artifacts/index.php', 'ui/artifacts/items-data.php', 'private/initialize.php', 'private/items_list.php'] as $file) {
                if (file_exists($root . '/' . $file)) { unlink($root . '/' . $file); }
            }
            foreach (['ui/artifacts', 'ui', 'private', ''] as $directory) { rmdir($root . '/' . $directory); }
        }
    }

    public function test_kept_choice_maps_onto_the_kept_and_secondary_filters(): void
    {
        $this->assertSame([15, 11, 10], $this->ids(['kept' => 'yes']));
        $this->assertSame([12, 14], $this->ids(['kept' => 'no']));
        $this->assertSame([12], $this->ids(['kept' => 'secondary_only']));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['kept' => 'allkeptandnot']));
    }

    public function test_explicit_type_lists_and_blank_get_types(): void
    {
        $this->assertSame([15, 11, 10, 14], $this->ids(['type' => ['1']]));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['type' => ['board-game' => '1', 'film' => '2']]));
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['type' => []]));
    }

    public function test_blank_get_type_entries_are_dropped_before_data_selection(): void
    {
        $this->assertSame([12], $this->ids(['type' => ['', '2']]));
        $this->assertSame([13, 15, 12, 11, 10, 14], $this->ids(['type' => ['']]));
    }

    public function test_tag_filter_lists_the_owners_tagged_items(): void
    {
        $this->assertSame([10], $this->ids(['tag' => 'Beach-Safe']));
    }

    public function test_players_and_age_filters_still_narrow_the_rows(): void
    {
        $this->assertSame([13, 10], $this->ids(['players' => '3', 'type' => ['']]));
        $this->assertSame([11, 10], $this->ids(['age' => '10']));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['age' => '10', 'age_unknown' => 'yes']));
        $this->assertSame([10], $this->ids(['players' => '3', 'age' => '10']));
        $this->assertSame([15, 12, 11, 10, 14], $this->ids(['showAttributes' => 'yes']));
    }

    public function test_recent_interaction_is_the_last_use_and_use_by_follows_it(): void
    {
        $catan = $this->row(10);
        $this->assertSame('2026-02-01', $catan['most_recent_use']);
        $this->assertSame('2026-07-31', $catan['use_by']);
        $this->assertSame('board-game', $catan['type']);
        $this->assertSame(['beach-safe'], $catan['tags']);
    }

    public function test_an_item_with_only_a_legacy_play_date_uses_it(): void
    {
        $azul = $this->row(11);
        $this->assertSame('2026-03-01', $azul['most_recent_use']);
        $this->assertSame('2026-08-28', $azul['use_by']);
    }

    public function test_an_unused_item_counts_from_acquisition(): void
    {
        $arrival = $this->row(12);
        $this->assertSame('', $arrival['most_recent_use']);
        $this->assertSame('2022-04-01', $arrival['use_by']);
        $this->assertFalse($arrival['use_by_overdue'], 'An item not kept is never overdue.');
    }

    public function test_the_items_own_frequency_replaces_the_page_interval(): void
    {
        $brass = $this->row(13);
        $this->assertSame('2026-01-01', $brass['most_recent_use']);
        $this->assertSame('2026-03-02', $brass['use_by']);
        $this->assertTrue($brass['use_by_overdue']);
        $this->assertSame('', $brass['type'], 'A legacy type string without a type id is no type.');
    }

    public function test_use_before_acquisition_and_fractional_item_frequency_keep_their_date_rules(): void
    {
        $this->db->query("UPDATE games SET Acq = '2026-02-14', interaction_frequency_days = 30 WHERE id = 10");
        $this->assertSame('2026-03-16', $this->row(10)['use_by']);
        $this->db->query("UPDATE games SET Acq = '2026-01-01', interaction_frequency_days = 0.5 WHERE id = 10");
        $this->assertSame('2026-02-02', $this->row(10)['use_by']);
    }

    public function test_the_page_interval_is_the_default_for_items_without_their_own(): void
    {
        $rows = array_column($this->rows(['interval' => '10', 'type' => ['']]), null, 'id');
        $this->assertSame('2026-02-21', $rows[10]['use_by']);
        $this->assertSame('2026-03-02', $rows[13]['use_by']);
    }
}

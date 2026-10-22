<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Seam: item_form_html(), the Item form's rendering half, for Create Item
 * and Edit Item. Owner 1 has board-game (1) and film (2), and film is
 * their Type for BoardGameGeek items.
 */
final class ItemFormTest extends TestCase
{
    private const HOSTILE = '"><script>alert(1)</script>';

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
        $this->db->query('ALTER TABLE types ADD COLUMN user_id INT NULL');
        $this->db->query('UPDATE types SET user_id = 1');
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/migrations/add-user-bgg-default-type.sql'));
        $this->db->query('UPDATE users SET bgg_default_type_id = 2 WHERE id = 1');
        if (!defined('DEFAULT_TYPE')) {
            define('DEFAULT_TYPE', 1);
        }
        require_once PRIVATE_PATH . '/item_form.php';
        $GLOBALS['db'] = $this->db;
        $_SESSION = ['user_id' => 1];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['db']);
        if ($this->db !== null) {
            $this->db->query('DROP DATABASE ' . $this->databaseName);
            $this->db->close();
        }
    }

    public function test_create_renders_its_fields_in_the_shared_order(): void
    {
        $html = item_form_html('create', item_form_create_values(), 90);

        $this->assertFieldOrder(
            ['Title', 'requestBggData', 'image_url', 'bgg_url', 'bgg_player_votes', 'bgg_age_basis', 'BGG_Rat', 'itemPicturePreview',
             'is_kept', 'type_search', 'Acq', 'interaction_frequency_days', 'SS', 'age', 'MnP', 'MxP', 'MnT', 'MxT', 'Yr', 'Notes', 'tags'],
            $html
        );
        $this->assertMatchesRegularExpression('/id="Title" autofocus/', $html);
        $this->assertStringContainsString('<label for="Title">Name</label>', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="bgg_url" id="bgg_url"/', $html);
        $this->assertStringContainsString('data-default-type-id="2" data-default-type-name="film" data-form-default-type-id="' . DEFAULT_TYPE . '"', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*id="bggMatchImage"/', $html);
        $this->assertStringContainsString('<label for="Yr">Year</label>', $html);
        $this->assertStringNotContainsString('data-keep-title', $html);
        $this->assertStringNotContainsString('to_get_rid_of', $html);
        $this->assertStringNotContainsString('is_in_secondary_collection', $html);
        $this->assertStringNotContainsString('data-bgg-basis', $html);
        $this->assertStringContainsString('<label for="MxT">Maximum Time</label>', $html);
    }

    public function test_create_starts_from_the_item_defaults_and_today(): void
    {
        $html = item_form_html('create', item_form_create_values(), 90);

        $this->assertMatchesRegularExpression('/id="is_kept" value="1" checked/', $html);
        $this->assertStringContainsString('id="Acq" value="' . app_today() . '"', $html);
        $this->assertBlankFrequency($html, '90');
        $this->assertStringContainsString('id="MnT" value="30"', $html);
        $this->assertStringContainsString('id="MxT" value="60"', $html);
        $this->assertStringContainsString('id="MnP" value="1"', $html);
        $this->assertStringContainsString('id="MxP" value="1"', $html);
        $this->assertStringContainsString('id="age" value="0"', $html);
        $this->assertStringContainsString('id="SS" aria-describedby="ss-hint" value=""', $html);
    }

    public function test_edit_renders_its_fields_in_the_shared_order(): void
    {
        $html = item_form_html('edit', $this->storedItem(), 90);

        $this->assertFieldOrder(
            ['Title', 'is_kept', 'to_get_rid_of', 'type_search', 'tags', 'Acq', 'interaction_frequency_days', 'SS', 'age',
             'MnP', 'MxP', 'MnT', 'MxT', 'Yr', 'requestBggData', 'bgg_player_votes', 'bgg_age_basis', 'BGG_Rat', 'bgg_url',
             'is_in_secondary_collection', 'Notes'],
            $html
        );
        $this->assertStringNotContainsString('autofocus', $html);
        $this->assertStringContainsString('<label for="Title">Name</label>', $html);
        $this->assertMatchesRegularExpression('/id="Title"[^>]*data-bgg-keep/', $html);
        $this->assertStringNotContainsString('data-keep-title', $html);
        $this->assertStringNotContainsString('ss-hint', $html);
        $this->assertStringNotContainsString('data-default-type-id', $html);
        $this->assertMatchesRegularExpression('/<input type="url" name="bgg_url" id="bgg_url"/', $html);
        $this->assertStringNotContainsString('image_url', $html);
        $this->assertStringNotContainsString('itemPicturePreview', $html);
        $this->assertStringContainsString('value="https://boardgamegeek.com/boardgame/19370"', $html);
        $this->assertStringContainsString('id="tags" value="loud, party"', $html);
        $this->assertStringContainsString('<input type="hidden" name="type" id="type" value="1" />', $html);
        $this->assertStringContainsString('<label for="MxT">Maximum Time</label>', $html);
    }

    public function test_the_form_marks_what_a_match_keeps_replaces_and_a_hand_edit_clears(): void
    {
        $create = item_form_html('create', item_form_create_values(), 90);
        $edit = item_form_html('edit', $this->storedItem(), 90);

        $this->assertDoesNotMatchRegularExpression('/id="Title"[^>]*data-bgg-keep/', $create);
        $this->assertMatchesRegularExpression('/id="Title"[^>]*data-bgg-keep/', $edit);
        $this->assertStringNotContainsString('data-keep-title', $create . $edit);
        $this->assertStringNotContainsString('data-bgg-preview', $edit);
        $this->assertStringNotContainsString('name="image_url"', $edit);

        $this->assertMatchesRegularExpression('/id="image_url"[^>]*data-bgg-replace[^>]*data-bgg-preview="itemPicturePreview"/', $create);
        foreach ([$create, $edit] as $html) {
            $this->assertMatchesRegularExpression('/id="BGG_Rat"[^>]*data-bgg-replace/', $html);
            $this->assertMatchesRegularExpression('/id="MnP"[^>]*data-bgg-clears="bgg_player_votes"/', $html);
            $this->assertMatchesRegularExpression('/id="MxP"[^>]*data-bgg-clears="bgg_player_votes"/', $html);
            $this->assertMatchesRegularExpression('/id="SS"[^>]*data-bgg-clears="bgg_player_votes"/', $html);
            $this->assertMatchesRegularExpression('/id="age"[^>]*data-bgg-clears="bgg_age_basis"/', $html);
            $this->assertMatchesRegularExpression('/name="bgg_url"[^>]*data-bgg-clears="bgg_player_votes bgg_age_basis BGG_Rat"/', $html);
        }
        $this->assertStringContainsString('data-bgg-basis="players" data-bgg-from="bgg_player_votes"', $edit);
        $this->assertStringContainsString('data-bgg-basis="sweet_spot" data-bgg-from="bgg_player_votes"', $edit);
        $this->assertStringContainsString('data-bgg-basis="age" data-bgg-from="bgg_age_basis"', $edit);
        $this->assertStringNotContainsString('data-bgg-from', $create);
    }

    public function test_edit_puts_the_vote_basis_under_each_bgg_field(): void
    {
        $html = item_form_html('edit', $this->storedItem(), 90);

        foreach (['SS' => 'sweet_spot', 'age' => 'age', 'MnP' => 'players', 'MxP' => 'players'] as $id => $group) {
            $this->assertMatchesRegularExpression(
                '/id="' . $id . '"[^>]*aria-describedby="' . $id . '-bgg-basis"[^>]*>\s*<p id="' . $id . '-bgg-basis" class="form-field-hint" data-bgg-basis="' . $group . '"/',
                $html,
                "{$id} should be described by its {$group} basis"
            );
        }
        $this->assertStringContainsString('From 9 BGG community votes', $html);
    }

    public function test_every_checkbox_posts_an_explicit_no(): void
    {
        $html = item_form_html('edit', ['is_kept' => 0, 'to_get_rid_of' => 1, 'is_in_secondary_collection' => 1] + $this->storedItem(), 90);

        foreach (['is_kept', 'to_get_rid_of', 'is_in_secondary_collection'] as $box) {
            $this->assertStringContainsString('<input type="hidden" name="' . $box . '" value="0" />', $html, $box);
        }
        $this->assertStringContainsString('id="is_kept" value="1" />', $html);
        $this->assertStringContainsString('id="to_get_rid_of" value="1" checked />', $html);
        $this->assertStringContainsString('id="is_in_secondary_collection" value="1" checked />', $html);
    }

    public function test_edit_shows_the_default_interval_as_a_placeholder_when_the_item_has_none(): void
    {
        $html = item_form_html('edit', ['interaction_frequency_days' => null] + $this->storedItem(), 45);

        $this->assertBlankFrequency($html, '45');
    }

    public function test_edit_shows_a_stored_interval_as_the_value(): void
    {
        $html = item_form_html('edit', $this->storedItem(), 90);

        $this->assertMatchesRegularExpression('/id="interaction_frequency_days"[^>]*value="60"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="interaction_frequency_days"[^>]*value=""/', $html);
    }

    public function test_edit_shows_a_stored_interval_even_when_it_equals_the_default(): void
    {
        $html = item_form_html('edit', ['interaction_frequency_days' => 90] + $this->storedItem(), 90);

        $this->assertMatchesRegularExpression('/id="interaction_frequency_days"[^>]*value="90"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="interaction_frequency_days"[^>]*value=""/', $html);
    }

    /** @dataProvider modes */
    public function test_a_rejected_blank_frequency_stays_empty(string $mode): void
    {
        $typed = item_form_input(['interaction_frequency_days' => '']);
        $base = $mode === 'create' ? item_form_create_values() : $this->storedItem();
        $html = item_form_html($mode, array_replace($base, $typed), 45);

        $this->assertBlankFrequency($html, '45');
    }

    /** @dataProvider modes */
    public function test_a_hostile_value_is_escaped_everywhere(string $mode): void
    {
        $fields = ['Title', 'tags', 'Acq', 'interaction_frequency_days', 'SS', 'Age', 'MnP', 'MxP', 'MnT', 'MxT', 'Yr', 'Notes', 'bgg_player_votes', 'bgg_age_basis'];
        $base = $mode === 'create' ? item_form_create_values() : $this->storedItem();
        $html = item_form_html($mode, array_fill_keys($fields, self::HOSTILE) + $base, 90);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /** @dataProvider modes */
    public function test_a_rejected_save_refills_every_typed_field(string $mode): void
    {
        $typed = item_form_input([
            'Title' => 'Quelf', 'type' => '2', 'tags' => 'party, loud', 'Acq' => '2026-01-02',
            'interaction_frequency_days' => '30', 'SS' => 'four', 'age' => '12', 'MnP' => '3', 'MxP' => '8',
            'MnT' => '20', 'MxT' => '45', 'Yr' => '2004', 'Notes' => 'Bring earplugs',
            'is_kept' => '0',
        ]);
        $base = $mode === 'create' ? item_form_create_values() : $this->storedItem();
        $html = item_form_html($mode, array_replace($base, $typed), 90);

        $this->assertStringContainsString('id="Title"' . ($mode === 'create' ? ' autofocus' : '') . ' value="Quelf"', $html);
        $this->assertStringContainsString('<input type="hidden" name="type" id="type" value="2" />', $html);
        $this->assertStringContainsString('id="tags" value="party, loud"', $html);
        $this->assertStringContainsString('id="Acq" value="2026-01-02"', $html);
        $this->assertMatchesRegularExpression('/id="interaction_frequency_days"[^>]*value="30"/', $html);
        $this->assertMatchesRegularExpression('/id="SS"[^>]*value="four"/', $html);
        foreach (['age' => '12', 'MnP' => '3', 'MxP' => '8', 'MnT' => '20', 'MxT' => '45', 'Yr' => '2004'] as $id => $typedValue) {
            $this->assertMatchesRegularExpression('/id="' . $id . '"[^>]*value="' . $typedValue . '"/', $html, $id);
        }
        $this->assertStringContainsString('>Bring earplugs</textarea>', $html);
        $this->assertStringContainsString('id="is_kept" value="1" />', $html);
    }

    public static function modes(): array
    {
        return ['create' => ['create'], 'edit' => ['edit']];
    }

    private function storedItem(): array
    {
        return [
            'id' => 10, 'Title' => 'Quelf', 'type_id' => 1, 'type_name' => 'board-game', 'tags' => ['loud', 'party'],
            'Acq' => '2025-05-06', 'interaction_frequency_days' => 60, 'SS' => '4', 'Age' => 12,
            'MnP' => 3, 'MxP' => 8, 'MnT' => 20, 'MxT' => 45, 'Yr' => 2004, 'Notes' => 'Loud',
            'image_url' => 'https://example.com/q.jpg', 'bgg_url' => 'https://boardgamegeek.com/boardgame/19370',
            'bgg_player_votes' => 9, 'bgg_age_basis' => 'community', 'BGG_Rat' => '6.10',
            'is_kept' => 1, 'to_get_rid_of' => 0, 'is_in_secondary_collection' => 0,
        ];
    }

    private function assertBlankFrequency(string $html, string $default): void
    {
        $this->assertMatchesRegularExpression(
            '/id="interaction_frequency_days"[^>]*aria-describedby="interaction_frequency_days-hint"[^>]*placeholder="'
                . preg_quote($default, '/') . '"[^>]*value=""/',
            $html
        );
        $this->assertStringContainsString(
            '<p id="interaction_frequency_days-hint" class="form-field-hint">Leave this blank to follow your default. It keeps following that default after you change it.</p>',
            $html
        );
        $this->assertSame(1, substr_count($html, 'name="interaction_frequency_days"'));
    }

    private function assertFieldOrder(array $ids, string $html): void
    {
        $positions = [];
        foreach ($ids as $id) {
            $position = strpos($html, 'id="' . $id . '"');
            $this->assertNotFalse($position, "missing #{$id}");
            $positions[$id] = $position;
        }
        $sorted = $positions;
        asort($sorted);
        $this->assertSame($ids, array_keys($sorted));
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

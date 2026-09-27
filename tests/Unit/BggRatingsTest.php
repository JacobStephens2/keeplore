<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';
require_once PROJECT_PATH . '/private/bgg_ratings.php';
require_once PROJECT_PATH . '/private/items_list.php';

/**
 * Seams:
 * - bgg_thing_id_from_url(): the BGG object an item's link names
 * - bgg_user_from_users_json(): a BGG username resolved to its id and spelling
 * - bgg_rating_from_collection_json(): one BGG user's rating and comment on one thing
 * - bgg_rating_from_input(): a rating typed on Edit Item
 * - items_list_present_row(): carries each imported BGG rating to /artifacts/
 */
class BggRatingsTest extends TestCase
{
    public function test_thing_id_comes_from_board_game_and_expansion_links(): void
    {
        $this->assertSame(147154, bgg_thing_id_from_url('https://boardgamegeek.com/boardgame/147154/blue-moon-legends'));
        $this->assertSame(421, bgg_thing_id_from_url('https://boardgamegeek.com/boardgame/421'));
        $this->assertSame(12, bgg_thing_id_from_url('https://www.boardgamegeek.com/boardgameexpansion/12/x'));
        $this->assertSame(55, bgg_thing_id_from_url('https://videogamegeek.com/videogame/55/y'));
    }

    public function test_links_that_name_no_thing_give_no_id(): void
    {
        $this->assertSame(0, bgg_thing_id_from_url(''));
        $this->assertSame(0, bgg_thing_id_from_url(null));
        $this->assertSame(0, bgg_thing_id_from_url('https://rpggeek.com/rpg/1234/some-system'));
        $this->assertSame(0, bgg_thing_id_from_url('https://boardgamegeek.com/profile/Gyges'));
        $this->assertSame(0, bgg_thing_id_from_url('https://example.com/boardgame/147154'));
    }

    public function test_username_resolves_to_bgg_spelling_and_id(): void
    {
        $json = '[{"type":"users","id":"63428","userid":63428,"username":"Gyges","href":"\/profile\/Gyges"}]';

        $this->assertSame(['id' => 63428, 'username' => 'Gyges'], bgg_user_from_users_json($json, 'gyges'));
    }

    public function test_unknown_username_resolves_to_null(): void
    {
        $this->assertNull(bgg_user_from_users_json('[]', 'nosuchuser'));
        $this->assertNull(bgg_user_from_users_json('not json', 'Gyges'));
        $this->assertNull(bgg_user_from_users_json(
            '[{"userid":1,"username":"GygesTwo"}]',
            'Gyges'
        ));
    }

    public function test_collection_entry_gives_rating_and_comment(): void
    {
        $json = json_encode(['items' => [[
            'objectid' => '147154',
            'objectname' => 'Blue Moon Legends',
            'rating' => 9.5,
            'rating_tstamp' => '2014-05-19 17:20:01',
            'textfield' => ['comment' => [
                'value' => "The best card game ever.\nI did not expect it. ",
                'tstamp' => '2014-05-19 17:26:34',
            ]],
        ]]]);

        $this->assertSame([
            'rating' => 9.5,
            'comment' => "The best card game ever.\nI did not expect it.",
            'rated_at' => '2014-05-19 17:20:01',
        ], bgg_rating_from_collection_json($json));
    }

    public function test_comment_without_rating_still_counts(): void
    {
        $json = json_encode(['items' => [[
            'rating' => null,
            'textfield' => ['comment' => ['value' => 'Never rated, but has thoughts.']],
        ]]]);

        $this->assertSame([
            'rating' => null,
            'comment' => 'Never rated, but has thoughts.',
            'rated_at' => null,
        ], bgg_rating_from_collection_json($json));
    }

    public function test_entry_with_neither_rating_nor_comment_is_null(): void
    {
        $this->assertNull(bgg_rating_from_collection_json('{"items":[]}'));
        $this->assertNull(bgg_rating_from_collection_json('not json'));
        $this->assertNull(bgg_rating_from_collection_json(json_encode(['items' => [[
            'rating' => 0,
            'status' => ['own' => true],
            'textfield' => ['comment' => ['value' => '  ']],
        ]]])));
    }

    public function test_item_page_shows_each_reviewers_score_and_comment(): void
    {
        $html = item_bgg_ratings_html([
            'Gyges' => [
                'rating' => 9.5,
                'comment' => "The best card game ever.\nA <towering> testament & more.",
                'url' => 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends',
            ],
        ]);

        $this->assertStringContainsString('Gyges rated it 9.5 out of 10 on BoardGameGeek', $html);
        $this->assertStringContainsString("The best card game ever.\nA &lt;towering&gt; testament &amp; more.", $html);
        $this->assertStringNotContainsString('<towering>', $html);
    }

    public function test_item_page_reads_whole_scores_without_decimals_and_handles_missing_parts(): void
    {
        $rated_only = item_bgg_ratings_html(['Gyges' => ['rating' => 8.0, 'comment' => null, 'url' => '']]);
        $comment_only = item_bgg_ratings_html(['Gyges' => ['rating' => null, 'comment' => 'Thoughts.', 'url' => '']]);

        $this->assertStringContainsString('Gyges rated it 8 out of 10 on BoardGameGeek', $rated_only);
        $this->assertStringNotContainsString('<blockquote', $rated_only);
        $this->assertStringContainsString('Gyges commented on BoardGameGeek', $comment_only);
        $this->assertStringContainsString('Thoughts.', $comment_only);
    }

    public function test_item_page_without_imported_ratings_shows_nothing(): void
    {
        $this->assertSame('', item_bgg_ratings_html([]));
    }

    public function test_list_row_carries_imported_ratings_by_reviewer(): void
    {
        $row = items_list_present_row([
            'id' => 7,
            'Title' => 'Blue Moon Legends',
            'is_kept' => 1,
            'Acq' => '2024-01-10',
            'bgg_ratings' => [
                'Gyges' => [
                    'rating' => 9.5,
                    'comment' => 'The best card game ever.',
                    'url' => 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends',
                ],
            ],
        ], 90, '2024-06-01');

        $this->assertSame([
            'Gyges' => [
                'rating' => 9.5,
                'comment' => 'The best card game ever.',
                'url' => 'https://boardgamegeek.com/boardgame/147154/blue-moon-legends',
            ],
        ], $row['bgg_ratings']);
    }

    public function test_list_row_without_ratings_has_an_empty_object(): void
    {
        $row = items_list_present_row(['id' => 8, 'Title' => 'Chess', 'Acq' => '2024-01-10'], 90, '2024-06-01');

        $this->assertEquals(new \stdClass(), $row['bgg_ratings']);
        $this->assertSame('{}', json_encode($row['bgg_ratings']));
    }

    public function test_edit_item_offers_an_editor_for_each_imported_reviewer(): void
    {
        $edit = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $save = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/bgg-rating-save.php');

        $this->assertStringContainsString("url_for('/artifacts/bgg-rating-save.php')", $edit);
        foreach (['artifact_id', 'bgg_username', 'rating', 'comment'] as $field) {
            $this->assertMatchesRegularExpression('/name="' . $field . '"/', $edit);
        }
        $this->assertStringContainsString('bgg_ratings_save_item(', $save);
        $this->assertStringContainsString('require_login()', $save);
    }

    public function test_item_page_marks_a_hand_entry_as_not_from_bgg(): void
    {
        $rated = item_bgg_ratings_html(['Gyges' => ['rating' => 7.0, 'comment' => 'Mine.', 'url' => '', 'manual' => true]]);
        $comment_only = item_bgg_ratings_html(['Gyges' => ['rating' => null, 'comment' => 'Mine.', 'url' => '', 'manual' => true]]);

        $this->assertStringContainsString('Gyges rated it 7 out of 10 (entered by hand)', $rated);
        $this->assertStringNotContainsString('on BoardGameGeek', $rated);
        $this->assertStringContainsString('Gyges commented (entered by hand)', $comment_only);
    }

    public function test_edit_item_offers_the_editor_whether_or_not_the_item_links_to_bgg(): void
    {
        $edit = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $editor_at = strpos($edit, "url_for('/artifacts/bgg-rating-save.php')");
        $link_check_at = strpos($edit, 'bgg_thing_id_from_url(');

        $this->assertNotFalse($editor_at);
        $this->assertNotFalse($link_check_at);
        $this->assertLessThan($link_check_at, $editor_at, 'the editor must not sit inside the BGG-link check');
    }

    public function test_rating_input_reads_blank_as_none_and_scores_from_one_to_ten(): void
    {
        $this->assertNull(bgg_rating_from_input(' '));
        $this->assertSame(8.5, bgg_rating_from_input(' 8.5 '));
        $this->assertSame(1.0, bgg_rating_from_input('1'));
        $this->assertSame(10.0, bgg_rating_from_input('10.00'));
        foreach (['0', '10.5', 'eight', '-3', '1e1', '8.125', '0x9'] as $bad) {
            $this->assertFalse(bgg_rating_from_input($bad), $bad . ' is not a BGG score');
        }
    }
}

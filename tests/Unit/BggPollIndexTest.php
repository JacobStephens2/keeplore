<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/bgg_poll_index.php';

/**
 * Reading BGG's ranked lists into Search BGG's index, and reading the search form.
 */
class BggPollIndexTest extends TestCase
{
    public function test_ranked_list_reads_each_thing_once_with_its_rank_and_rating(): void
    {
        $json = json_encode(['items' => [
            [
                'objecttype' => 'thing', 'objectid' => '254640', 'name' => 'Just One', 'yearpublished' => '2018',
                'rank' => '159', 'average' => '7.58888', 'usersrated' => '41000',
                'images' => ['thumb' => 'https://cf.geekdo-images.com/x__small/pic1.jpg'],
            ],
            ['objecttype' => 'thing', 'objectid' => '254640', 'name' => 'Just One', 'rank' => '159'],
            ['objecttype' => 'thing', 'objectid' => '99', 'name' => 'Unranked', 'yearpublished' => '0', 'rank' => '0', 'average' => '0'],
            ['objecttype' => 'family', 'objectid' => '5', 'name' => 'Not a game'],
        ]]);

        $this->assertSame(
            [
                [
                    'thing_id' => 254640, 'name' => 'Just One', 'year_published' => 2018, 'bgg_rank' => 159,
                    'average' => 7.58888, 'users_rated' => 41000, 'image_url' => 'https://cf.geekdo-images.com/x__small/pic1.jpg',
                ],
                [
                    'thing_id' => 99, 'name' => 'Unranked', 'year_published' => null, 'bgg_rank' => null,
                    'average' => null, 'users_rated' => 0, 'image_url' => null,
                ],
            ],
            bgg_poll_listing_from_json($json)
        );
        $this->assertSame([], bgg_poll_listing_from_json('{"items":[]}'));
        $this->assertFalse(bgg_poll_listing_from_json('not json'));
    }

    public function test_search_form_reads_best_count_age_and_minimum_votes(): void
    {
        $this->assertSame(
            ['best' => 7, 'age' => 6, 'min_votes' => 20, 'skip_open' => false],
            bgg_poll_search_filters(['best' => '7', 'age' => '6', 'min_votes' => '20'])
        );
    }

    public function test_search_form_uses_either_filter_alone(): void
    {
        $this->assertSame(['best' => 7, 'age' => null, 'min_votes' => 0, 'skip_open' => false], bgg_poll_search_filters(['best' => '7', 'age' => '']));
        $this->assertSame(['best' => null, 'age' => 6, 'min_votes' => 0, 'skip_open' => false], bgg_poll_search_filters(['age' => '6']));
        $this->assertSame(['best' => null, 'age' => null, 'min_votes' => 0, 'skip_open' => false], bgg_poll_search_filters([]));
    }

    public function test_search_form_ignores_values_out_of_range(): void
    {
        $this->assertSame(
            ['best' => null, 'age' => null, 'min_votes' => 0, 'skip_open' => false],
            bgg_poll_search_filters(['best' => '0', 'age' => 'six', 'min_votes' => '-3'])
        );
        $this->assertSame(['best' => null, 'age' => null, 'min_votes' => 0, 'skip_open' => false], bgg_poll_search_filters(['best' => ['7']]));
    }

    public function test_search_form_reads_leaving_out_open_ended_best(): void
    {
        $this->assertTrue(bgg_poll_search_filters(['best' => '9', 'skip_open' => '1'])['skip_open']);
        $this->assertFalse(bgg_poll_search_filters(['best' => '9', 'skip_open' => ''])['skip_open']);
        $this->assertFalse(bgg_poll_search_filters(['best' => '9', 'skip_open' => ['1']])['skip_open']);
    }
}

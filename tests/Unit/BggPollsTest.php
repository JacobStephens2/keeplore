<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/bgg_polls.php';

/**
 * One BoardGameGeek poll reply: Best player counts, the text of those votes,
 * the player-poll vote count, the Community age, the Sweet spot an Item
 * records, and the recommended player range.
 */
class BggPollsTest extends TestCase
{
    public function test_a_queued_or_garbled_reply_is_not_an_answer(): void
    {
        $this->assertFalse(bgg_polls_from_dynamic_json(''));
        $this->assertFalse(bgg_polls_from_dynamic_json('not json'));
        $this->assertFalse(bgg_polls_from_dynamic_json('{"item":{}}'));
        $this->assertFalse(bgg_polls_from_dynamic_json('{"item":{"polls":null}}'));
        $this->assertFalse(bgg_polls_from_dynamic_json('{"item":{"stats":{"average":"7.09"}}}'));
    }

    public function test_polls_give_best_counts_player_votes_community_age_sweet_spot_and_recommended_range(): void
    {
        $json = json_encode(['item' => ['polls' => [
            'userplayers' => [
                'best' => [['min' => 6, 'max' => 6], ['min' => 8, 'max' => 8]],
                'recommended' => [['min' => 4, 'max' => 8]],
                'totalvotes' => '1456',
            ],
            'playerage' => '10+',
        ]]]);

        $this->assertSame(
            [
                'best' => [
                    ['players' => 6, 'open_only' => false],
                    ['players' => 8, 'open_only' => false],
                ],
                'best_text' => '6, 8',
                'player_votes' => 1456,
                'community_age' => 10,
                'sweet_spot' => '06,08',
                'recommended' => [4, 8],
            ],
            bgg_polls_from_dynamic_json($json)
        );
    }

    public function test_a_best_range_lists_every_count_and_an_open_end_does_not_expand_sweet_spot_or_recommended(): void
    {
        $range = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 6, 'max' => 7]],
            'recommended' => [['min' => 4, 'max' => 8]],
            'totalvotes' => '505',
        ], 'playerage' => '8+']]]);
        $open = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 9, 'max' => null]],
            'recommended' => [['min' => 9, 'max' => null], ['min' => 3, 'max' => 5]],
            'totalvotes' => '12',
        ]]]]);

        $closed = bgg_polls_from_dynamic_json($range);
        $this->assertSame('6-7', $closed['best_text']);
        $this->assertSame('06,07', $closed['sweet_spot']);
        $this->assertSame([4, 8], $closed['recommended']);
        $this->assertSame(
            [
                ['players' => 6, 'open_only' => false],
                ['players' => 7, 'open_only' => false],
            ],
            $closed['best']
        );

        $this->assertSame(
            [
                'best' => [
                    ['players' => 9, 'open_only' => false],
                    ['players' => 10, 'open_only' => true],
                    ['players' => 11, 'open_only' => true],
                    ['players' => 12, 'open_only' => true],
                    ['players' => 13, 'open_only' => true],
                    ['players' => 14, 'open_only' => true],
                    ['players' => 15, 'open_only' => true],
                    ['players' => 16, 'open_only' => true],
                    ['players' => 17, 'open_only' => true],
                    ['players' => 18, 'open_only' => true],
                    ['players' => 19, 'open_only' => true],
                    ['players' => 20, 'open_only' => true],
                ],
                'best_text' => '9+',
                'player_votes' => 12,
                'community_age' => null,
                'sweet_spot' => '09',
                'recommended' => [3, 9],
            ],
            bgg_polls_from_dynamic_json($open)
        );
    }

    public function test_best_text_lists_ranges_smallest_first_and_sweet_spot_keeps_only_named_counts(): void
    {
        $json = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 4, 'max' => null], ['min' => 2, 'max' => 2]],
            'totalvotes' => '30',
        ]]]]);

        $polls = bgg_polls_from_dynamic_json($json);

        $this->assertSame('2, 4+', $polls['best_text']);
        $this->assertSame('02,04', $polls['sweet_spot']);
        $this->assertNull($polls['recommended']);
        $this->assertSame(
            [
                ['players' => 2, 'open_only' => false],
                ['players' => 4, 'open_only' => false],
            ],
            array_values(array_filter($polls['best'], fn ($count) => $count['open_only'] === false))
        );
        $this->assertSame(5, $polls['best'][2]['players']);
        $this->assertTrue($polls['best'][2]['open_only']);
        $this->assertSame(20, $polls['best'][count($polls['best']) - 1]['players']);
        $this->assertTrue($polls['best'][count($polls['best']) - 1]['open_only']);
        $this->assertCount(18, $polls['best']);
    }

    public function test_a_closed_range_covering_a_count_wins_over_an_open_end(): void
    {
        $json = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 9, 'max' => null], ['min' => 6, 'max' => 9]],
            'totalvotes' => '40',
        ]]]]);

        $polls = bgg_polls_from_dynamic_json($json);
        $by_count = [];
        foreach ($polls['best'] as $count) {
            $by_count[$count['players']] = $count['open_only'];
        }

        $this->assertSame('6-9, 9+', $polls['best_text']);
        $this->assertSame('06,07,08,09', $polls['sweet_spot']);
        $this->assertFalse($by_count[6]);
        $this->assertFalse($by_count[8]);
        $this->assertFalse($by_count[9]);
        $this->assertTrue($by_count[10]);
        $this->assertTrue($by_count[20]);
        $this->assertCount(15, $polls['best']);
    }

    public function test_overlapping_ranges_produce_one_count(): void
    {
        $json = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 4, 'max' => 6], ['min' => 5, 'max' => 7]],
            'totalvotes' => '8',
        ]]]]);

        $this->assertSame(
            [
                'best' => [
                    ['players' => 4, 'open_only' => false],
                    ['players' => 5, 'open_only' => false],
                    ['players' => 6, 'open_only' => false],
                    ['players' => 7, 'open_only' => false],
                ],
                'best_text' => '4-6, 5-7',
                'player_votes' => 8,
                'community_age' => null,
                'sweet_spot' => '04,05,06,07',
                'recommended' => null,
            ],
            bgg_polls_from_dynamic_json($json)
        );
    }

    public function test_a_closed_range_whose_max_is_below_its_min_stays_the_minimum(): void
    {
        $json = json_encode(['item' => ['polls' => ['userplayers' => [
            'best' => [['min' => 6, 'max' => 4]],
            'recommended' => [['min' => 6, 'max' => 4]],
            'totalvotes' => '2',
        ]]]]);

        $polls = bgg_polls_from_dynamic_json($json);

        $this->assertSame([['players' => 6, 'open_only' => false]], $polls['best']);
        $this->assertSame('6', $polls['best_text']);
        $this->assertSame('06', $polls['sweet_spot']);
        $this->assertSame([6, 6], $polls['recommended']);
    }

    public function test_a_game_nobody_voted_on_has_no_best_counts_sweet_spot_or_age(): void
    {
        $no_votes = json_encode(['item' => ['polls' => [
            'userplayers' => ['best' => [], 'recommended' => [], 'totalvotes' => '0'],
            'playerage' => '(no votes)',
        ]]]);
        $blank_age = json_encode(['item' => ['polls' => [
            'userplayers' => ['best' => [], 'totalvotes' => '-3'],
            'playerage' => '',
        ]]]);

        $this->assertSame(
            [
                'best' => [],
                'best_text' => '',
                'player_votes' => 0,
                'community_age' => null,
                'sweet_spot' => null,
                'recommended' => null,
            ],
            bgg_polls_from_dynamic_json($no_votes)
        );
        $blank = bgg_polls_from_dynamic_json($blank_age);
        $this->assertSame(0, $blank['player_votes']);
        $this->assertNull($blank['community_age']);
        $this->assertNull($blank['sweet_spot']);
    }
}

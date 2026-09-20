<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/bgg_lookup.php';

class BggLookupTest extends TestCase
{
    public function test_search_json_lists_id_and_name_in_order(): void
    {
        $json = json_encode([
            'search' => 'Sky Team',
            'items' => [
                ['objecttype' => 'thing', 'objectid' => '373106', 'name' => 'Sky Team'],
                ['objecttype' => 'thing', 'objectid' => '426898', 'name' => 'Sky Team: Agon Spiele Insert'],
            ],
        ]);

        $this->assertSame(
            [
                ['id' => 373106, 'name' => 'Sky Team'],
                ['id' => 426898, 'name' => 'Sky Team: Agon Spiele Insert'],
            ],
            bgg_search_candidates_from_json($json)
        );
    }

    public function test_search_json_with_no_items_is_empty(): void
    {
        $this->assertSame([], bgg_search_candidates_from_json('{"search":"zzzz","items":[]}'));
        $this->assertSame([], bgg_search_candidates_from_json('not json'));
    }

    public function test_search_json_keeps_the_first_name_for_a_duplicate_id(): void
    {
        $json = json_encode([
            'items' => [
                ['objectid' => '414502', 'name' => 'Sky Team: BUD Budapest Ferenc Liszt'],
                ['objectid' => '414502', 'name' => 'Sky Team: Budapest Ferenc Liszt'],
            ],
        ]);
        $this->assertSame(
            [['id' => 414502, 'name' => 'Sky Team: BUD Budapest Ferenc Liszt']],
            bgg_search_candidates_from_json($json)
        );
    }

    public function test_sky_team_payloads_fill_create_form_fields(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 373106,
                'name' => 'Sky Team',
                'yearpublished' => 2023,
                'minplayers' => 2,
                'maxplayers' => 2,
                'minplaytime' => 20,
                'maxplaytime' => 20,
                'minage' => 10,
                'canonical_link' => 'https://boardgamegeek.com/boardgame/373106/sky-team',
            ],
        ]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => [
                        'best' => [['min' => 2, 'max' => 2]],
                        'totalvotes' => '444',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                'match' => [
                    'id' => 373106,
                    'name' => 'Sky Team',
                    'year' => '2023',
                    'url' => 'https://boardgamegeek.com/boardgame/373106/sky-team',
                ],
                'fields' => [
                    'Title' => 'Sky Team',
                    'SS' => '02',
                    'MnP' => '2',
                    'MxP' => '2',
                    'MnT' => '20',
                    'MxT' => '20',
                    'Age' => '10',
                ],
            ],
            bgg_form_fields_from_json($itemJson, $dynamicJson)
        );
    }

    public function test_sweet_spot_expands_a_best_with_range(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 13,
                'name' => 'Catan',
                'yearpublished' => 1995,
                'minplayers' => 3,
                'maxplayers' => 4,
                'minplaytime' => 60,
                'maxplaytime' => 120,
                'minage' => 10,
            ],
        ]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => [
                        'best' => [['min' => 3, 'max' => 4]],
                    ],
                ],
            ],
        ]);

        $mapped = bgg_form_fields_from_json($itemJson, $dynamicJson);
        $this->assertSame('03,04', $mapped['fields']['SS']);
        $this->assertSame('https://boardgamegeek.com/boardgame/13', $mapped['match']['url']);
    }

    public function test_sweet_spot_is_omitted_when_the_poll_is_missing(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 1,
                'name' => 'No Poll',
                'minplayers' => 1,
                'maxplayers' => 4,
                'minplaytime' => 30,
                'maxplaytime' => 30,
                'minage' => 8,
            ],
        ]);

        $mapped = bgg_form_fields_from_json($itemJson, '{}');
        $this->assertArrayNotHasKey('SS', $mapped['fields']);
        $this->assertSame('1', $mapped['fields']['MnP']);
        $this->assertSame('4', $mapped['fields']['MxP']);
    }

    public function test_preferred_candidate_is_the_exact_name_match(): void
    {
        $candidates = [
            ['id' => 426898, 'name' => 'Sky Team: Agon Spiele Insert'],
            ['id' => 373106, 'name' => 'Sky Team'],
        ];
        $this->assertSame(
            ['id' => 373106, 'name' => 'Sky Team'],
            bgg_preferred_candidate($candidates, 'sky team')
        );
    }

    public function test_preferred_candidate_falls_back_to_the_first_result(): void
    {
        $candidates = [
            ['id' => 9209, 'name' => 'Ticket to Ride'],
            ['id' => 14996, 'name' => 'Ticket to Ride: Europe'],
        ];
        $this->assertSame(
            ['id' => 9209, 'name' => 'Ticket to Ride'],
            bgg_preferred_candidate($candidates, 'ticket')
        );
    }

    public function test_search_rejects_a_blank_name(): void
    {
        $result = bgg_search('   ', function () {
            $this->fail('BoardGameGeek should not be called for a blank name');
        });
        $this->assertFalse($result['ok']);
        $this->assertSame('Enter an item name first.', $result['error']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_search_returns_candidates_from_injected_http(): void
    {
        $json = json_encode([
            'items' => [
                ['objectid' => '373106', 'name' => 'Sky Team'],
                ['objectid' => '426898', 'name' => 'Sky Team: Agon Spiele Insert'],
            ],
        ]);
        $called = [];
        $result = bgg_search('Sky Team', function ($url) use ($json, &$called) {
            $called[] = $url;
            return $json;
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(373106, $result['preferred']['id']);
        $this->assertCount(2, $result['candidates']);
        $this->assertCount(1, $called);
        $this->assertStringContainsString('objecttype=thing', $called[0]);
        $this->assertStringContainsString('search=Sky%20Team', $called[0]);
        $this->assertStringContainsString('showcount=20', $called[0]);
        $this->assertStringStartsWith('https://api.geekdo.com/api/geekitems?', $called[0]);
    }

    public function test_search_reports_no_match(): void
    {
        $result = bgg_search('zzzznotagame', function () {
            return '{"search":"zzzznotagame","items":[]}';
        });
        $this->assertFalse($result['ok']);
        $this->assertSame('No BoardGameGeek match for that name.', $result['error']);
    }

    public function test_search_reports_unreachable_bgg(): void
    {
        $result = bgg_search('Sky Team', function () {
            throw new \RuntimeException('timeout');
        });
        $this->assertFalse($result['ok']);
        $this->assertSame('Could not reach BoardGameGeek.', $result['error']);
    }

    public function test_lookup_by_name_confirms_preferred_match_and_lists_alternatives(): void
    {
        $searchJson = json_encode([
            'items' => [
                ['objectid' => '373106', 'name' => 'Sky Team'],
                ['objectid' => '426898', 'name' => 'Sky Team: Agon Spiele Insert'],
            ],
        ]);
        $itemJson = json_encode([
            'item' => [
                'objectid' => 373106,
                'name' => 'Sky Team',
                'yearpublished' => '2023',
                'minplayers' => '2',
                'maxplayers' => '2',
                'minplaytime' => '20',
                'maxplaytime' => '20',
                'minage' => '10',
                'canonical_link' => 'https://boardgamegeek.com/boardgame/373106/sky-team',
            ],
        ]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => [
                        'best' => [['min' => 2, 'max' => 2]],
                    ],
                ],
            ],
        ]);

        $result = bgg_lookup_name('Sky Team', function ($url) use ($searchJson, $itemJson, $dynamicJson) {
            if (str_contains($url, 'search=')) {
                return $searchJson;
            }
            if (str_contains($url, 'dynamicinfo')) {
                $this->assertStringContainsString('objectid=373106', $url);
                return $dynamicJson;
            }
            $this->assertStringContainsString('objectid=373106', $url);
            $this->assertStringContainsString('objecttype=thing', $url);
            return $itemJson;
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('Sky Team', $result['match']['name']);
        $this->assertSame('2023', $result['match']['year']);
        $this->assertSame('02', $result['fields']['SS']);
        $this->assertSame('20', $result['fields']['MnT']);
        $this->assertSame(
            [['id' => 426898, 'name' => 'Sky Team: Agon Spiele Insert']],
            $result['alternatives']
        );
    }

    public function test_fields_for_id_loads_the_chosen_game(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 426898,
                'name' => 'Sky Team: Agon Spiele Insert',
                'yearpublished' => 2024,
                'minplayers' => 2,
                'maxplayers' => 2,
                'minplaytime' => 15,
                'maxplaytime' => 15,
                'minage' => 10,
            ],
        ]);
        $result = bgg_fields_for_id(426898, function ($url) use ($itemJson) {
            if (str_contains($url, 'dynamicinfo')) {
                return '{"item":{}}';
            }
            $this->assertStringContainsString('objectid=426898', $url);
            return $itemJson;
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('Sky Team: Agon Spiele Insert', $result['match']['name']);
        $this->assertSame('15', $result['fields']['MnT']);
        $this->assertArrayNotHasKey('SS', $result['fields']);
    }
}

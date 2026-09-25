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
        $cover = 'https://cf.geekdo-images.com/uXMeQzNenHb3zK7Hoa6b2w__itemrep/img/oaw-LYEIaB20e79Y568JgyHZ5NQ=/fit-in/246x300/filters:strip_icc()/pic7398904.jpg';
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
                'imageurl' => $cover,
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
                    'source' => 'BGG',
                    'image' => $cover,
                ],
                'fields' => [
                    'Title' => 'Sky Team',
                    'SS' => '02',
                    'MnP' => '2',
                    'MxP' => '2',
                    'MnT' => '20',
                    'MxT' => '20',
                    'Age' => '10',
                    'Yr' => '2023',
                    'image_url' => $cover,
                    'bgg_url' => 'https://boardgamegeek.com/boardgame/373106/sky-team',
                    'bgg_player_votes' => '0',
                    'bgg_age_basis' => 'publisher',
                ],
            ],
            bgg_form_fields_from_json($itemJson, $dynamicJson)
        );
    }

    public function test_community_age_and_player_range_win_over_the_publisher(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 326964,
                'name' => 'Mountain Goats: Expansion Pack',
                'minplayers' => 1,
                'maxplayers' => 6,
                'minage' => 14,
            ],
        ]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => [
                        'best' => [['min' => 3, 'max' => 4]],
                        'recommended' => [['min' => 2, 'max' => 3], ['min' => 5, 'max' => 5]],
                        'totalvotes' => '5',
                    ],
                    'playerage' => '8+',
                ],
            ],
        ]);

        $fields = bgg_form_fields_from_json($itemJson, $dynamicJson)['fields'];
        $this->assertSame('8', $fields['Age']);
        $this->assertSame('2', $fields['MnP']);
        $this->assertSame('5', $fields['MxP']);
        $this->assertSame('03,04', $fields['SS']);
        $this->assertSame('5', $fields['bgg_player_votes']);
        $this->assertSame('community', $fields['bgg_age_basis']);
    }

    public function test_publisher_age_and_players_fill_in_when_nobody_voted(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 1,
                'name' => 'Unvoted',
                'minplayers' => 1,
                'maxplayers' => 4,
                'minage' => 10,
            ],
        ]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => ['best' => [], 'recommended' => [], 'totalvotes' => '0'],
                    'playerage' => '',
                ],
            ],
        ]);

        $fields = bgg_form_fields_from_json($itemJson, $dynamicJson)['fields'];
        $this->assertSame('10', $fields['Age']);
        $this->assertSame('1', $fields['MnP']);
        $this->assertSame('4', $fields['MxP']);
        $this->assertSame('0', $fields['bgg_player_votes']);
        $this->assertSame('publisher', $fields['bgg_age_basis']);
    }

    public function test_votes_without_a_recommended_range_count_as_publisher_players(): void
    {
        $itemJson = json_encode(['item' => ['objectid' => 2, 'name' => 'Thin Poll', 'minplayers' => 2, 'maxplayers' => 6]]);
        $dynamicJson = json_encode([
            'item' => [
                'polls' => [
                    'userplayers' => ['best' => [], 'recommended' => [], 'totalvotes' => '3'],
                ],
            ],
        ]);

        $fields = bgg_form_fields_from_json($itemJson, $dynamicJson)['fields'];
        $this->assertSame('2', $fields['MnP']);
        $this->assertSame('0', $fields['bgg_player_votes']);
    }

    public function test_link_is_omitted_when_not_a_geek_site(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 5,
                'name' => 'Odd Link',
                'canonical_link' => 'https://example.com/boardgame/5',
            ],
        ]);
        $this->assertArrayNotHasKey('bgg_url', bgg_form_fields_from_json($itemJson, '{}')['fields']);
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
        $this->assertSame('BGG', $mapped['match']['source']);
    }

    public function test_rpgitem_payload_is_labeled_rpgg(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 161315,
                'name' => 'Ravine',
                'yearpublished' => 2014,
                'subtype' => 'rpgitem',
                'canonical_link' => 'https://rpggeek.com/rpgitem/161315/ravine',
            ],
        ]);
        $mapped = bgg_form_fields_from_json($itemJson, '{}');
        $this->assertSame('RPGG', $mapped['match']['source']);
        $this->assertSame('2014', $mapped['match']['year']);
        $this->assertSame('https://rpggeek.com/rpgitem/161315/ravine', $mapped['match']['url']);
    }

    public function test_videogame_payload_is_labeled_vgg(): void
    {
        $itemJson = json_encode([
            'item' => [
                'objectid' => 123,
                'name' => 'Ravine',
                'yearpublished' => 2010,
                'subtype' => 'videogame',
                'canonical_link' => 'https://videogamegeek.com/videogame/123/ravine',
            ],
        ]);
        $this->assertSame('VGG', bgg_form_fields_from_json($itemJson, '{}')['match']['source']);
    }

    public function test_cover_falls_back_to_preview_then_original(): void
    {
        $preview = 'https://cf.geekdo-images.com/example__previewthumb/img/a=/fit-in/300x320/pic1.jpg';
        $original = 'https://cf.geekdo-images.com/example__original/img/b=/0x0/pic1.jpg';

        $previewMapped = bgg_form_fields_from_json(json_encode([
            'item' => [
                'objectid' => 1,
                'name' => 'Preview Only',
                'images' => ['previewthumb' => $preview, 'original' => $original],
            ],
        ]), '{}');
        $this->assertSame($preview, $previewMapped['match']['image']);
        $this->assertSame($preview, $previewMapped['fields']['image_url']);

        $originalMapped = bgg_form_fields_from_json(json_encode([
            'item' => [
                'objectid' => 2,
                'name' => 'Original Only',
                'images' => ['original' => $original],
            ],
        ]), '{}');
        $this->assertSame($original, $originalMapped['match']['image']);
    }

    public function test_cover_is_omitted_when_missing_or_not_https(): void
    {
        $missing = bgg_form_fields_from_json(json_encode([
            'item' => ['objectid' => 1, 'name' => 'No Art'],
        ]), '{}');
        $this->assertArrayNotHasKey('image', $missing['match']);
        $this->assertArrayNotHasKey('image_url', $missing['fields']);

        $insecure = bgg_form_fields_from_json(json_encode([
            'item' => [
                'objectid' => 2,
                'name' => 'Http Art',
                'imageurl' => 'http://cf.geekdo-images.com/insecure.jpg',
            ],
        ]), '{}');
        $this->assertArrayNotHasKey('image', $insecure['match']);
        $this->assertArrayNotHasKey('image_url', $insecure['fields']);
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

    public function test_preferred_candidate_ignores_a_disambiguating_parenthetical(): void
    {
        $candidates = [
            ['id' => 1, 'name' => 'Chess Variants', 'source' => 'BGG'],
            ['id' => 171, 'name' => 'Chess', 'source' => 'BGG'],
        ];
        $this->assertSame(171, bgg_preferred_candidate($candidates, 'Chess (game)')['id']);
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

    public function test_preferred_candidate_matches_when_the_query_omits_a_colon(): void
    {
        $candidates = [
            ['id' => 146508, 'name' => 'T.I.M.E Stories'],
            ['id' => 189686, 'name' => 'T.I.M.E Stories: Under the Mask'],
        ];
        $this->assertSame(
            ['id' => 189686, 'name' => 'T.I.M.E Stories: Under the Mask'],
            bgg_preferred_candidate($candidates, 't.i.m.e stories under the mask')
        );
    }

    public function test_preferred_exact_name_picks_bgg_over_rpgg(): void
    {
        $candidates = [
            ['id' => 161315, 'name' => 'Ravine', 'source' => 'RPGG', 'year' => '2014'],
            ['id' => 237728, 'name' => 'Ravine', 'source' => 'BGG', 'year' => '2017'],
        ];
        $this->assertSame(
            237728,
            bgg_preferred_candidate($candidates, 'Ravine')['id']
        );
    }

    public function test_query_variants_drop_a_disambiguating_parenthetical(): void
    {
        $this->assertContains('Chess', bgg_search_query_variants('Chess (game)'));
        $this->assertContains('Lost Cities', bgg_search_query_variants('Lost Cities (simpliciter)'));
        $this->assertSame('Chess (game)', bgg_search_query_variants('Chess (game)')[0]);
    }

    public function test_query_variants_move_a_trailing_article_to_the_front(): void
    {
        $this->assertContains('The Magic Labyrinth', bgg_search_query_variants('Magic Labyrinth (The)'));
    }

    public function test_search_finds_a_title_with_a_disambiguating_parenthetical(): void
    {
        $queries = [];
        $result = bgg_search('Chess (game)', function ($url) use (&$queries) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            $queries[] = $params['search'];
            if ($params['search'] === 'Chess') {
                return json_encode(['items' => [['objectid' => 171, 'name' => 'Chess']]]);
            }
            return json_encode(['items' => []]);
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(171, $result['preferred']['id']);
        $this->assertContains('Chess', $queries);
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

    public function test_search_finds_a_colon_title_when_the_query_omits_the_colon(): void
    {
        $hit = json_encode([
            'items' => [
                ['objectid' => '189686', 'name' => 'T.I.M.E Stories: Under the Mask'],
            ],
        ]);
        $called = [];
        $result = bgg_search('t.i.m.e stories under the mask', function ($url) use ($hit, &$called) {
            $called[] = $url;
            if (str_contains($url, rawurlencode('t.i.m.e stories: under the mask'))) {
                return $hit;
            }
            return '{"items":[]}';
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(189686, $result['preferred']['id']);
        $this->assertSame('T.I.M.E Stories: Under the Mask', $result['preferred']['name']);
        $this->assertStringContainsString(rawurlencode('t.i.m.e stories under the mask'), $called[0]);
        $this->assertContains(
            bgg_api_root() . '/geekitems?objecttype=thing&search=' . rawurlencode('t.i.m.e stories: under the mask') . '&showcount=20',
            $called
        );
    }

    public function test_search_keeps_looking_when_an_earlier_colon_variant_is_a_different_title(): void
    {
        $unrelated = json_encode([
            'items' => [
                ['objectid' => '1', 'name' => 'Something Else'],
            ],
        ]);
        $hit = json_encode([
            'items' => [
                ['objectid' => '189686', 'name' => 'T.I.M.E Stories: Under the Mask'],
            ],
        ]);
        $result = bgg_search('t.i.m.e stories under the mask', function ($url) use ($unrelated, $hit) {
            if (str_contains($url, rawurlencode('t.i.m.e: stories under the mask'))) {
                return $unrelated;
            }
            if (str_contains($url, rawurlencode('t.i.m.e stories: under the mask'))) {
                return $hit;
            }
            return '{"items":[]}';
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(189686, $result['preferred']['id']);
        $this->assertSame('T.I.M.E Stories: Under the Mask', $result['preferred']['name']);
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
        $cover = 'https://cf.geekdo-images.com/uXMeQzNenHb3zK7Hoa6b2w__itemrep/img/oaw-LYEIaB20e79Y568JgyHZ5NQ=/fit-in/246x300/filters:strip_icc()/pic7398904.jpg';
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
                'imageurl' => $cover,
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

        $insertJson = json_encode([
            'item' => [
                'objectid' => 426898,
                'name' => 'Sky Team: Agon Spiele Insert',
                'yearpublished' => 2024,
                'subtype' => 'boardgame',
                'canonical_link' => 'https://boardgamegeek.com/boardgameaccessory/426898/sky-team-agon-spiele-insert',
            ],
        ]);

        $result = bgg_lookup_name('Sky Team', function ($url) use ($searchJson, $itemJson, $insertJson, $dynamicJson) {
            if (str_contains($url, 'search=')) {
                return $searchJson;
            }
            if (str_contains($url, 'dynamicinfo')) {
                $this->assertStringContainsString('objectid=373106', $url);
                return $dynamicJson;
            }
            if (str_contains($url, 'objectid=426898')) {
                return $insertJson;
            }
            $this->assertStringContainsString('objectid=373106', $url);
            return $itemJson;
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('Sky Team', $result['match']['name']);
        $this->assertSame('2023', $result['match']['year']);
        $this->assertSame('BGG', $result['match']['source']);
        $this->assertSame($cover, $result['match']['image']);
        $this->assertSame($cover, $result['fields']['image_url']);
        $this->assertSame('02', $result['fields']['SS']);
        $this->assertSame('20', $result['fields']['MnT']);
        $this->assertSame(
            [[
                'id' => 426898,
                'name' => 'Sky Team: Agon Spiele Insert',
                'year' => '2024',
                'source' => 'BGG',
            ]],
            $result['alternatives']
        );
    }

    public function test_lookup_by_name_notes_rpgg_when_the_exact_hit_is_not_bgg(): void
    {
        $searchJson = json_encode([
            'items' => [
                ['objectid' => '161315', 'name' => 'Ravine'],
                ['objectid' => '245407', 'name' => 'Dry Ravine Complex'],
            ],
        ]);
        $rpgJson = json_encode([
            'item' => [
                'objectid' => 161315,
                'name' => 'Ravine',
                'yearpublished' => 2014,
                'subtype' => 'rpgitem',
                'canonical_link' => 'https://rpggeek.com/rpgitem/161315/ravine',
            ],
        ]);
        $altJson = json_encode([
            'item' => [
                'objectid' => 245407,
                'name' => 'Dry Ravine Complex',
                'yearpublished' => 2018,
                'subtype' => 'rpgitem',
                'canonical_link' => 'https://rpggeek.com/rpgitem/245407/dry-ravine-complex',
            ],
        ]);

        $result = bgg_lookup_name('Ravine', function ($url) use ($searchJson, $rpgJson, $altJson) {
            if (str_contains($url, 'search=')) {
                return $searchJson;
            }
            if (str_contains($url, 'dynamicinfo')) {
                return '{"item":{}}';
            }
            if (str_contains($url, 'objectid=245407')) {
                return $altJson;
            }
            return $rpgJson;
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('RPGG', $result['match']['source']);
        $this->assertSame('2014', $result['match']['year']);
        $this->assertSame('2018', $result['alternatives'][0]['year']);
        $this->assertSame('RPGG', $result['alternatives'][0]['source']);
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

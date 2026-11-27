<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Tests\Support\LocalPhpServer;
use Tests\Support\LocalUiRoute;

require_once __DIR__ . '/MySqlTestCase.php';
require_once __DIR__ . '/../Support/LocalUiRoute.php';

final class EventPageTest extends MySqlTestCase
{
    private const ROUTE = '/events/show.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runSql(file_get_contents(PROJECT_PATH . '/database/local-schema.sql'));
        $this->runSql(<<<'SQL'
            INSERT INTO users (id, first_name, last_name, email, username, hashed_password) VALUES
                (1, 'Event', 'Owner', 'owner@example.test', 'owner', ''),
                (2, 'Private', 'Owner', 'private@example.test', 'private', '');
            INSERT INTO events (id, user_id, name, starts_on, ends_on, notes) VALUES
                (1, 1, 'Beach & <em> week', '2027-07-03', '2027-07-10', 'Bring & <em> share'),
                (2, 1, 'Cabin weekend', NULL, NULL, ''),
                (3, 2, 'Private event', NULL, NULL, '');
            INSERT INTO types (id, objectType, user_id) VALUES (1, 'table game', 1);
            INSERT INTO games (id, user_id, Title, type_id, is_kept, MnP, MxP, SS, Age, MnT, MxT) VALUES
                (10, 1, 'Catan & <em>', 1, 1, 3, 4, '3,4', 10, 60, 120),
                (11, 1, 'Azul & <em>', 1, 0, 2, 4, '2,4', 8, 30, 45),
                (20, 2, 'Private item', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL);
            INSERT INTO event_items (event_id, artifact_id, setting, note, is_packed) VALUES
                (1, 10, 'Porch', 'Bring & <em> sleeves', 1), (1, 20, '', '', 1), (2, 10, '', '', 0);
            INSERT INTO item_tags (user_id, artifact_id, tag) VALUES (1, 10, 'casual');
            INSERT INTO players (id, user_id, FirstName, LastName, birth_year) VALUES
                (100, 1, 'Jo & <em>', 'Vale', 2015), (101, 1, 'Sam & <em>', 'Lee', NULL),
                (200, 2, 'Private', 'player', 2012), (201, 2, 'Other private', 'player', NULL);
            INSERT INTO event_players VALUES (1, 100), (1, 200);
            SQL);
    }

    public function test_the_route_remembers_normalized_grouping_per_event_and_leaves_it_intact_after_a_404(): void
    {
        $this->withRoute(function (LocalPhpServer $server): void {
            $first = $server->request(self::ROUTE . '?id=1&tags=Casual%2C+CASUAL&order=desc&at_least=01', ['X-Test-Login: owner']);
            $this->assertSame(200, $first['status'], $first['body']);
            $this->assertSame(1, preg_match('/^Set-Cookie: (PHPSESSID=[^;\r\n]+)/mi', $first['headers'], $cookie));
            $session = ['Cookie: ' . $cookie[1]];
            $this->assertGrouping($this->xpath($first['body']), 'tag', 'casual', 'desc', '1');

            $other = $server->request(self::ROUTE . '?id=2', $session);
            $this->assertSame(200, $other['status'], $other['body']);
            $this->assertGrouping($this->xpath($other['body']), 'none', '', 'asc', '');

            foreach (['', '?id=0', '?id[]=1', '?id=999', '?id=3'] as $query) {
                $response = $server->request(self::ROUTE . $query, $session);
                $this->assertSame(404, $response['status'], $query);
            }

            $this->db->query('UPDATE events SET user_id = 2 WHERE id = 1');
            $inaccessible = $server->request(self::ROUTE . '?id=1&by=setting&tags=&order=asc', $session);
            $this->assertSame(404, $inaccessible['status']);
            $this->db->query('UPDATE events SET user_id = 1 WHERE id = 1');

            $revisit = $server->request(self::ROUTE . '?id=1', $session);
            $this->assertSame(200, $revisit['status'], $revisit['body']);
            $this->assertGrouping($this->xpath($revisit['body']), 'tag', 'casual', 'desc', '1');

            $clear = $server->request(self::ROUTE . '?id=1&then=none&tags=&order=asc&at_least=', $session);
            $this->assertSame(200, $clear['status'], $clear['body']);
            $this->assertGrouping($this->xpath($clear['body']), 'none', '', 'asc', '');
            $afterClear = $server->request(self::ROUTE . '?id=1', $session);
            $this->assertSame(200, $afterClear['status'], $afterClear['body']);
            $this->assertGrouping($this->xpath($afterClear['body']), 'none', '', 'asc', '');
        });
    }

    public function test_the_route_renders_the_owners_plan_and_add_choices_with_escaped_text(): void
    {
        $this->withRoute(function (LocalPhpServer $server): void {
            $response = $server->request(self::ROUTE . '?id=1&by=none', ['X-Test-Login: owner']);
            $this->assertSame(200, $response['status'], $response['body']);
            $xpath = $this->xpath($response['body']);

            $this->assertSame('Beach & <em> week', $xpath->evaluate('normalize-space(//main//h1)'));
            $this->assertSame('Jul 3 – Jul 10, 2027', $xpath->evaluate('normalize-space(//p[@class="page-lede"])'));
            $this->assertSame('Bring & <em> share', $xpath->evaluate('normalize-space(//p[@class="event-notes"])'));
            $this->assertSame('1', $xpath->evaluate('string(//*[@id="event-packed-count"])'));
            $this->assertSame(1, $xpath->query('//ul[@class="event-checklist"]/li')->length);
            $this->assertSame('Catan & <em>', $xpath->evaluate('normalize-space(//ul[@class="event-checklist"]//a)'));
            $this->assertSame('casual', $xpath->evaluate('normalize-space(//ul[@class="event-checklist"]/li/small)'));
            $this->assertSame(
                '- [x] Catan & <em>, 3–4 (3, 4), 10 yrs, 60–120 min, Porch, Bring & <em> sleeves',
                $xpath->evaluate('normalize-space(//textarea[@id="event-text"])')
            );
            $this->assertSame('Jo & <em> Vale', $xpath->evaluate('normalize-space(//ul[@class="event-player-list"]//a)'));
            $this->assertSame('age 12', $xpath->evaluate('normalize-space(//ul[@class="event-player-list"]//small)'));
            $this->assertSame('Sam & <em> Lee', $xpath->evaluate('normalize-space(//*[@id="event-player-list"]/label/span)'));
            $this->assertSame('sam & <em> lee', $xpath->evaluate('string(//*[@id="event-player-list"]/label/@data-title)'));
            $this->assertSame(
                'Azul & <em> Not kept 2–4 players, best 2, 4 · Age 8+',
                $xpath->evaluate('normalize-space(//*[@id="event-add-list"]/label/span)')
            );
            $this->assertSame('1', $xpath->evaluate('string(//*[@id="event-add-list"]/label/@data-game)'));
            $this->assertSame(0, $xpath->query('//main//em')->length);
            $this->assertStringNotContainsString('Private item', $response['body']);
            $this->assertStringNotContainsString('Private player', $response['body']);
            $this->assertStringNotContainsString('Other private player', $response['body']);
        });
    }

    private function assertGrouping(DOMXPath $xpath, string $then, string $tags, string $order, string $minimum): void
    {
        $this->assertSame($then, $xpath->evaluate('string(//select[@name="then"]/option[@selected]/@value)'));
        $this->assertSame($tags, $xpath->evaluate('string(//input[@name="tags"]/@value)'));
        $this->assertSame($order, $xpath->evaluate('string(//select[@name="order"]/option[@selected]/@value)'));
        $this->assertSame($minimum, $xpath->evaluate('string(//input[@name="at_least"]/@value)'));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
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

    private function withRoute(callable $exercise): void
    {
        // Only the first request seeds login. Reopening the same session through
        // the real route lets its grouping writes survive between requests.
        $session = <<<'PHP'
            <?php
            if (($_SERVER['HTTP_X_TEST_LOGIN'] ?? '') === 'owner') {
                session_start();
                $_SESSION = ['user_id' => 1, 'user_group' => 1, 'player_id' => 100, 'logged_in' => true, 'username' => 'Owner'];
                session_write_close();
            }
            PHP;
        LocalUiRoute::run(self::ROUTE, $this->db, $session, $exercise);
    }
}

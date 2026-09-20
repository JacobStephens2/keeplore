<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/agent_api_docs.php';

class AgentApiDocsTest extends TestCase
{
    private function docs(): array
    {
        return agent_api_docs('api.keeplore.app');
    }

    private function endpointsById(): array
    {
        $byId = [];
        foreach ($this->docs()['endpoints'] as $endpoint) {
            $byId[$endpoint['id']] = $endpoint;
        }
        return $byId;
    }

    public function test_catalog_uses_http_for_loopback_hosts(): void
    {
        $this->assertSame('http://127.0.0.1:8788', agent_api_docs('127.0.0.1:8788')['base_url']);
        $this->assertSame('http://localhost:8788', agent_api_docs('localhost:8788')['base_url']);
    }

    public function test_catalog_names_the_live_api_host_and_bearer_auth(): void
    {
        $docs = $this->docs();
        $this->assertSame('https://api.keeplore.app', $docs['base_url']);
        $this->assertSame('Bearer', $docs['auth']['scheme']);
        $this->assertSame('Authorization: Bearer <agent-key>', $docs['auth']['header']);
        $this->assertSame('/settings/agent-keys.php', $docs['auth']['issue_path']);
        $this->assertSame('ak_', $docs['auth']['token_prefix']);
        $this->assertSame('reads plus the kept toggle', $docs['auth']['scope']);
    }

    public function test_catalog_documents_the_sixty_request_rate_limit(): void
    {
        $docs = $this->docs();
        $this->assertSame(60, $docs['rate_limit']['requests']);
        $this->assertSame(60, $docs['rate_limit']['window_seconds']);
    }

    public function test_catalog_lists_the_agent_read_and_kept_endpoints(): void
    {
        $ids = array_column($this->docs()['endpoints'], 'id');
        $this->assertSame(
            [
                'list-items',
                'show-item',
                'list-uses',
                'list-proposals',
                'list-types',
                'upcoming-interactions',
                'set-kept',
            ],
            $ids
        );
    }

    public function test_list_items_uses_a_json_body_on_the_collection_endpoint(): void
    {
        $endpoint = $this->endpointsById()['list-items'];
        $this->assertSame('POST', $endpoint['method']);
        $this->assertSame('/artifacts.php', $endpoint['path']);
        $this->assertContains('page', $endpoint['body_fields']);
        $this->assertContains('per_page', $endpoint['body_fields']);
        $this->assertContains('query', $endpoint['body_fields']);
        $this->assertContains('tag', $endpoint['body_fields']);
        $this->assertTrue($endpoint['get_lists_first_page']);
    }

    public function test_list_and_show_payloads_include_each_items_tags(): void
    {
        $listNotes = implode(' ', $this->endpointsById()['list-items']['notes']);
        $showNotes = implode(' ', $this->endpointsById()['show-item']['notes']);
        $this->assertStringContainsString('tags', $listNotes);
        $this->assertStringContainsString('tags', $showNotes);
    }

    public function test_show_item_and_uses_are_get_reads(): void
    {
        $item = $this->endpointsById()['show-item'];
        $this->assertSame('GET', $item['method']);
        $this->assertSame('/artifact.php', $item['path']);
        $this->assertContains('id', $item['query_fields']);

        $uses = $this->endpointsById()['list-uses'];
        $this->assertSame('GET', $uses['method']);
        $this->assertSame('/uses.php', $uses['path']);
        $this->assertContains('artifact_id', $uses['query_fields']);
    }

    public function test_proposals_report_uses_glossary_sort_keys(): void
    {
        $endpoint = $this->endpointsById()['list-proposals'];
        $this->assertSame('GET', $endpoint['method']);
        $this->assertSame('/proposals.php', $endpoint['path']);
        $this->assertContains('start', $endpoint['query_fields']);
        $this->assertContains('end', $endpoint['query_fields']);
        $this->assertContains('include_other', $endpoint['query_fields']);
        $this->assertSame(
            ['item_name', 'explicit_declines', 'chose_something_else'],
            $endpoint['sort_values']
        );
    }

    public function test_kept_toggle_is_the_only_write_and_takes_strict_is_kept(): void
    {
        $endpoint = $this->endpointsById()['set-kept'];
        $this->assertSame('POST', $endpoint['method']);
        $this->assertSame('/artifact-kept.php', $endpoint['path']);
        $this->assertSame(['id', 'is_kept'], $endpoint['body_fields']);
        $this->assertSame([0, 1], $endpoint['is_kept_values']);
        $this->assertSame(
            'Agent keys permit reads plus the kept toggle only.',
            $this->docs()['denied_message']
        );
        $this->assertNotContains('set-tags', array_column($this->docs()['endpoints'], 'id'));
    }

    public function test_discovery_map_matches_catalog_paths(): void
    {
        $discovery = agent_api_discovery('api.keeplore.app');
        $this->assertSame(
            'Authorization: Bearer <agent-key> (see /settings/agent-keys.php)',
            $discovery['agent_auth']
        );
        $this->assertSame(
            'https://api.keeplore.app/artifacts.php',
            $discovery['endpoints']['POST /artifacts.php']
        );
        $this->assertSame(
            'https://api.keeplore.app/artifact.php',
            $discovery['endpoints']['GET /artifact.php?id=<id>']
        );
        $this->assertSame(
            'https://api.keeplore.app/artifact-kept.php',
            $discovery['endpoints']['POST /artifact-kept.php']
        );
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shared browser API client: an expired session's 401 sends the
 * visitor to log in before any other error handling.
 */
class ApiClientTest extends TestCase
{
    public function test_the_api_client_node_tests_pass(): void
    {
        // PHPUnit has no JS runner; this is the suite hook for the node tests.
        $script = PROJECT_PATH . '/tests/Unit/api-client.test.mjs';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}

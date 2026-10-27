<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServiceWorkerCacheTest extends TestCase
{
    public function test_service_worker_cache_node_tests_pass(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/service-worker-cache.test.js';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}

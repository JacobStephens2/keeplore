<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NativeNotificationsTest extends TestCase
{
    public function test_the_native_notification_node_tests_pass(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/native-notifications.test.mjs';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}

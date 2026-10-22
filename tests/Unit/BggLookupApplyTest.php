<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: the BoardGameGeek lookup apply on a document. The page and this
 * test call the same function. It writes whatever the payload names, and
 * a hand edit clears whatever the input's mark names.
 */
class BggLookupApplyTest extends TestCase
{
    public function test_applying_a_match_and_a_hand_edit(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/bgg-lookup-apply.test.js';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}

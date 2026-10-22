<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/explore.php';

/**
 * Seam: the Explore module's comparison of a Candidate group date or a Last
 * use with the cutoff day. The cutoff itself comes from the App day.
 */
class CandidateDateTest extends TestCase
{
    public function test_the_cutoff_day_is_marked_and_the_day_after_and_a_blank_are_not(): void
    {
        $cutoff = '2026-07-24';

        $this->assertTrue(candidate_date_is_marked('2026-07-24', $cutoff));
        $this->assertTrue(candidate_date_is_marked('2026-07-23', $cutoff));
        $this->assertTrue(candidate_date_is_marked('2026-07-24 18:30:00', $cutoff));
        $this->assertFalse(candidate_date_is_marked('2026-07-25', $cutoff));
        $this->assertFalse(candidate_date_is_marked('', $cutoff));
        $this->assertFalse(candidate_date_is_marked(null, $cutoff));
        $this->assertFalse(candidate_date_is_marked('not-a-date', $cutoff));
        $this->assertFalse(candidate_date_is_marked('2026-02-31', $cutoff));
    }
}

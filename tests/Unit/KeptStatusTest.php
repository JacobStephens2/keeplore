<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';

class KeptStatusTest extends TestCase
{
    // -----------------------------------------------------------------
    // artifact_is_kept(): kept means kept only
    // -----------------------------------------------------------------

    public function test_new_column_wins_over_legacy(): void
    {
        $this->assertTrue(artifact_is_kept(['is_kept' => 1, 'KeptCol' => 0]));
        $this->assertFalse(artifact_is_kept(['is_kept' => 0, 'KeptCol' => 1]));
    }

    public function test_legacy_fallback_during_overlap(): void
    {
        $this->assertTrue(artifact_is_kept(['KeptCol' => '1']));
        $this->assertFalse(artifact_is_kept(['KeptCol' => '0']));
    }

    public function test_format_flags_never_count_as_kept(): void
    {
        $this->assertFalse(artifact_is_kept(['is_kept' => 0, 'KeptDig' => 1, 'KeptPhys' => 1]));
        $this->assertFalse(artifact_is_kept(['KeptCol' => 0, 'KeptDig' => 1, 'KeptPhys' => 1]));
    }

    public function test_objects_and_missing_rows(): void
    {
        $row = new \stdClass();
        $row->is_kept = '1';
        $this->assertTrue(artifact_is_kept($row));
        $this->assertFalse(artifact_is_kept([]));
        $this->assertFalse(artifact_is_kept(null));
    }

    // -----------------------------------------------------------------
    // artifact_is_in_secondary_collection()
    // -----------------------------------------------------------------

    public function test_secondary_new_column_and_legacy_fallback(): void
    {
        $this->assertTrue(artifact_is_in_secondary_collection(['is_in_secondary_collection' => 1]));
        $this->assertFalse(artifact_is_in_secondary_collection(['is_in_secondary_collection' => 0]));
        $this->assertTrue(artifact_is_in_secondary_collection(['InSecondaryCollection' => 'yes']));
        $this->assertFalse(artifact_is_in_secondary_collection(['InSecondaryCollection' => 'no']));
        $this->assertFalse(artifact_is_in_secondary_collection(['InSecondaryCollection' => null]));
    }

    // -----------------------------------------------------------------
    // normalizers
    // -----------------------------------------------------------------

    public function test_normalize_kept_value(): void
    {
        $this->assertSame(1, normalize_kept_value(1));
        $this->assertSame(1, normalize_kept_value('1'));
        $this->assertSame(1, normalize_kept_value(true));
        $this->assertSame(1, normalize_kept_value('yes'));
        $this->assertSame(0, normalize_kept_value(0));
        $this->assertSame(0, normalize_kept_value('0'));
        $this->assertSame(0, normalize_kept_value('no'));
        $this->assertSame(0, normalize_kept_value(null));
    }

    public function test_normalize_secondary_membership(): void
    {
        $this->assertSame(1, normalize_secondary_membership('yes'));
        $this->assertSame(1, normalize_secondary_membership(1));
        $this->assertSame(0, normalize_secondary_membership('no'));
        $this->assertSame(0, normalize_secondary_membership(null));
    }

    public function test_normalize_format_flag_preserves_null(): void
    {
        $this->assertNull(normalize_format_flag(null));
        $this->assertNull(normalize_format_flag(''));
        $this->assertSame(1, normalize_format_flag(1));
        $this->assertSame(0, normalize_format_flag(0));
    }

    // -----------------------------------------------------------------
    // fill_legacy_kept_keys(): new-form input bridge
    // -----------------------------------------------------------------

    public function test_fill_derives_legacy_keys_from_new_form(): void
    {
        $filled = fill_legacy_kept_keys(['is_kept' => 1, 'is_in_secondary_collection' => 1, 'is_digital' => 1]);
        $this->assertSame('1', $filled['KeptCol']);
        $this->assertSame('yes', $filled['InSecondaryCollection']);
        $this->assertSame(1, $filled['KeptDig']);
        $this->assertArrayNotHasKey('KeptPhys', $filled);
    }

    public function test_fill_never_overrides_supplied_legacy_keys(): void
    {
        $filled = fill_legacy_kept_keys(['is_kept' => 1, 'KeptCol' => '0']);
        $this->assertSame('0', $filled['KeptCol']);
    }

    public function test_fill_passes_through_non_arrays(): void
    {
        $this->assertNull(fill_legacy_kept_keys(null));
    }
}

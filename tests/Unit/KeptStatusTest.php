<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';

class KeptStatusTest extends TestCase
{
    // -----------------------------------------------------------------
    // artifact_is_kept(): kept means kept only
    // -----------------------------------------------------------------

    public function test_kept_rows_read_as_kept(): void
    {
        $this->assertTrue(artifact_is_kept(['is_kept' => 1]));
        $this->assertTrue(artifact_is_kept(['is_kept' => '1']));
    }

    public function test_unkept_rows_read_as_unkept(): void
    {
        $this->assertFalse(artifact_is_kept(['is_kept' => 0]));
        $this->assertFalse(artifact_is_kept(['is_kept' => '0']));
    }

    public function test_format_flags_never_count_as_kept(): void
    {
        $this->assertFalse(artifact_is_kept(['is_kept' => 0, 'is_digital' => 1, 'is_physical' => 1]));
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

    public function test_secondary_membership(): void
    {
        $this->assertTrue(artifact_is_in_secondary_collection(['is_in_secondary_collection' => 1]));
        $this->assertFalse(artifact_is_in_secondary_collection(['is_in_secondary_collection' => 0]));
        $this->assertFalse(artifact_is_in_secondary_collection([]));
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
    // artifact_flag_sql(): SQL form of the same predicates
    // -----------------------------------------------------------------

    public function test_flag_sql_true_matches_only_one(): void
    {
        $this->assertSame('games.is_kept = 1', artifact_flag_sql('games.is_kept', true));
    }

    public function test_flag_sql_false_treats_null_as_unset(): void
    {
        $this->assertSame('COALESCE(games.is_physical, 0) <> 1', artifact_flag_sql('games.is_physical', false));
    }
}

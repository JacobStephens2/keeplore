<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/query_functions/player_queries.php';

class PlayerMergeTest extends TestCase
{
    private function player(int $id, int $user_id, $represents_user_id = null): array
    {
        return [
            'id' => $id,
            'FirstName' => 'Mel',
            'LastName' => 'Test',
            'user_id' => $user_id,
            'represents_user_id' => $represents_user_id,
        ];
    }

    public function test_valid_merge_has_no_errors(): void
    {
        $errors = validate_player_merge($this->player(318, 8), $this->player(314, 8), 8);
        $this->assertSame([], $errors);
    }

    public function test_missing_records_are_rejected(): void
    {
        $this->assertNotEmpty(validate_player_merge(false, $this->player(314, 8), 8));
        $this->assertNotEmpty(validate_player_merge($this->player(318, 8), false, 8));
    }

    public function test_self_merge_is_rejected(): void
    {
        $errors = validate_player_merge($this->player(318, 8), $this->player(318, 8), 8);
        $this->assertNotEmpty($errors);
    }

    public function test_cross_account_merge_is_rejected(): void
    {
        $this->assertNotEmpty(validate_player_merge($this->player(318, 8), $this->player(314, 9), 8));
        $this->assertNotEmpty(validate_player_merge($this->player(318, 9), $this->player(314, 8), 8));
    }

    public function test_merge_into_non_me_record_when_loser_is_me_is_rejected(): void
    {
        $me = $this->player(318, 8, 8);
        $other = $this->player(314, 8, null);
        $this->assertNotEmpty(validate_player_merge($other, $me, 8));
    }

    public function test_merge_into_me_record_is_allowed(): void
    {
        $me = $this->player(318, 8, 8);
        $other = $this->player(314, 8, null);
        $this->assertSame([], validate_player_merge($me, $other, 8));
    }
}

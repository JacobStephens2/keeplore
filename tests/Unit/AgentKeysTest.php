<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/kept_status.php';
require_once PROJECT_PATH . '/private/agent_keys.php';

class AgentKeysTest extends TestCase
{
    // -----------------------------------------------------------------
    // token generation and hashing
    // -----------------------------------------------------------------

    public function test_generated_tokens_are_unique_bearer_shaped_strings(): void
    {
        $first = generate_agent_api_token();
        $second = generate_agent_api_token();
        $this->assertNotSame($first, $second);
        foreach ([$first, $second] as $token) {
            $this->assertMatchesRegularExpression('/\Aak_[A-Za-z0-9\-_]{40,}\z/', $token);
        }
    }

    public function test_key_hash_is_deterministic_sha256(): void
    {
        $this->assertSame(hash('sha256', 'ak_example'), agent_api_key_hash('ak_example'));
        $this->assertNotSame(agent_api_key_hash('ak_one'), agent_api_key_hash('ak_two'));
    }

    // -----------------------------------------------------------------
    // resolve_agent_kept_input(): legacy alias round-trip, input side
    // -----------------------------------------------------------------

    public function test_new_name_wins_over_legacy_alias(): void
    {
        $body = new \stdClass();
        $body->is_kept = 1;
        $body->KeptCol = 0;
        $this->assertSame(1, resolve_agent_kept_input($body));
    }

    public function test_legacy_alias_accepted_when_new_name_absent(): void
    {
        $this->assertSame(0, resolve_agent_kept_input(['KeptCol' => '0']));
        $this->assertSame(1, resolve_agent_kept_input(['KeptCol' => 1]));
    }

    public function test_missing_or_empty_kept_input_resolves_to_null(): void
    {
        $this->assertNull(resolve_agent_kept_input(new \stdClass()));
        $this->assertNull(resolve_agent_kept_input(['is_kept' => '']));
        $this->assertNull(resolve_agent_kept_input(null));
    }

    // -----------------------------------------------------------------
    // agent_kept_fields(): legacy alias round-trip, output side
    // -----------------------------------------------------------------

    public function test_output_carries_both_vocabularies(): void
    {
        $fields = agent_kept_fields(['is_kept' => 1, 'is_in_secondary_collection' => 0]);
        $this->assertSame(1, $fields['is_kept']);
        $this->assertSame(1, $fields['KeptCol']);
        $this->assertSame(0, $fields['is_in_secondary_collection']);
        $this->assertNull($fields['InSecondaryCollection']);
    }

    public function test_output_secondary_yes_and_format_fallback(): void
    {
        $fields = agent_kept_fields(['KeptCol' => 0, 'InSecondaryCollection' => 'yes', 'KeptDig' => 1]);
        $this->assertSame(0, $fields['is_kept']);
        $this->assertSame(1, $fields['is_in_secondary_collection']);
        $this->assertSame('yes', $fields['InSecondaryCollection']);
        $this->assertSame(1, $fields['is_digital']);
        $this->assertSame(1, $fields['KeptDig']);
        $this->assertNull($fields['is_physical']);
    }

    public function test_format_flags_never_leak_into_kept(): void
    {
        $fields = agent_kept_fields(['is_kept' => 0, 'is_digital' => 1, 'is_physical' => 1]);
        $this->assertSame(0, $fields['is_kept']);
        $this->assertSame(0, $fields['KeptCol']);
    }
}

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
}

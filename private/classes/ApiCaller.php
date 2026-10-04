<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/AgentKeys.php';

/**
 * Who an HTTP API request is, from the credentials it carries, and whose
 * data it may act for. A session or an agent key acts for its own user,
 * whatever the request names. The master key has no user of its own: it
 * acts for the existing user a request names, or for no one. An agent key
 * may only read and flip kept (ADR-0002); every other handler asks for its
 * refusal.
 */
final class ApiCaller
{
    /** The response fields of every agent-key refusal (ADR-0002). */
    public const AGENT_KEY_REFUSAL = [
        'authenticated' => true,
        'message' => 'Agent keys permit reads plus the kept toggle only.',
    ];

    private function __construct(
        private mysqli $db,
        private ?int $userId,
        private bool $isAgentKey
    ) {
    }

    /**
     * The caller a request's credentials name, or null when they don't
     * authenticate. $accessToken is the session cookie's access token and
     * $authorization the Authorization header, each null when absent. An
     * access token means a session or nothing; otherwise a Bearer header
     * means an agent key or nothing; otherwise the header must be the master
     * key.
     */
    public static function fromCredentials(mysqli $db, ?string $accessToken, ?string $authorization): ?self
    {
        if ($accessToken !== null) {
            try {
                $session = JWT::decode($accessToken, new Key(JWT_SECRET, 'HS256'));
            } catch (Exception $e) {
                return null;
            }
            return isset($session->user_id) ? self::session($db, (int) $session->user_id) : null;
        }
        if ($authorization !== null && strncasecmp($authorization, 'Bearer ', 7) === 0) {
            $token = trim(substr($authorization, 7));
            $userId = $token === '' ? null : AgentKeys::userForToken($db, $token);
            return $userId === null ? null : self::agentKey($db, $userId);
        }
        if ($authorization !== null && hash_equals(ARTIFACTS_API_KEY, $authorization)) {
            return self::masterKey($db);
        }
        return null;
    }

    /** A signed-in session, acting for $userId. */
    public static function session(mysqli $db, int $userId): self
    {
        return new self($db, $userId, false);
    }

    /** One of $userId's agent keys, acting for them. */
    public static function agentKey(mysqli $db, int $userId): self
    {
        return new self($db, $userId, true);
    }

    /** The master key, acting for the existing user a request names. */
    public static function masterKey(mysqli $db): self
    {
        return new self($db, null, false);
    }

    /**
     * Whose data the request acts for: the credential's own user, ignoring
     * $requested_user_id. For the master key, the user $requested_user_id
     * names when it is a positive whole number naming an existing user, and
     * null otherwise.
     */
    public function owner($requested_user_id = null): ?int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }
        $user_id = filter_var($requested_user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($user_id === false) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists ? $user_id : null;
    }

    /** [403, response fields] for an agent key, which may only read and flip kept; null otherwise. */
    public function agentKeyRefusal(): ?array
    {
        if (!$this->isAgentKey) {
            return null;
        }
        return [403, self::AGENT_KEY_REFUSAL];
    }
}

?>

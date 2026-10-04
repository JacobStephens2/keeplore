<?php

/**
 * The owner's agent keys (ADR-0002): each lets a remote agent read the
 * owner's items, uses and proposals and flip kept over HTTP. A key's token
 * exists in plain text only when it is issued; only its hash is stored.
 * Revoking a key stops its token working at once.
 *
 * Bad input throws InvalidArgumentException; a key the owner doesn't have,
 * or has already revoked, throws OutOfBoundsException.
 */
final class AgentKeys
{
    /** The agent name's column width. */
    public const MAX_NAME_LENGTH = 100;

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /**
     * The user whose active key $token is, recording the key as used; null
     * for an unknown token or a revoked key's. Static because the token is
     * what names the owner.
     */
    public static function userForToken(mysqli $db, string $token): ?int
    {
        $hash = self::hash($token);
        $stmt = $db->prepare('SELECT id, user_id FROM agent_api_keys WHERE key_hash = ? AND revoked_at IS NULL');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $key = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($key === null) {
            return null;
        }
        $stmt = $db->prepare('UPDATE agent_api_keys SET last_used_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $key['id']);
        $stmt->execute();
        $stmt->close();
        return (int) $key['user_id'];
    }

    /**
     * Issue a key for $agentName (trimmed, 1-100 characters) and return
     * ['id' => its id, 'token' => its token]. This is the only time the
     * token exists.
     */
    public function issue(string $agentName): array
    {
        $agentName = trim($agentName);
        if ($agentName === '') {
            throw new InvalidArgumentException('Agent name cannot be blank.');
        }
        if (mb_strlen($agentName) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Agent name must be ' . self::MAX_NAME_LENGTH . ' characters or fewer.');
        }
        $token = 'ak_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = self::hash($token);
        $stmt = $this->db->prepare('INSERT INTO agent_api_keys (user_id, agent_name, key_hash) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->userId, $agentName, $hash);
        $stmt->execute();
        $stmt->close();
        return ['id' => (int) $this->db->insert_id, 'token' => $token];
    }

    /** The owner's keys, newest first, each with its id, agent_name, created_at, last_used_at and revoked_at. */
    public function all(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, agent_name, created_at, last_used_at, revoked_at FROM agent_api_keys WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $keys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $keys;
    }

    /** Revoke one of the owner's active keys. */
    public function revoke(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE agent_api_keys SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $revoked = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$revoked) {
            throw new OutOfBoundsException('Agent key not found.');
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

?>

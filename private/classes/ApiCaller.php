<?php

/**
 * Who an authenticated HTTP API request is, and whose data it may act for.
 * A session or an agent key acts for its own user, whatever the request
 * names. The master key has no user of its own: it acts for the existing
 * user a request names, or for no one. An agent key may only read and flip
 * kept (ADR-0002); every other handler asks for its refusal.
 */
final class ApiCaller
{
    private function __construct(
        private mysqli $db,
        private string $type,
        private ?int $userId
    ) {
    }

    /** The caller authenticate()'s result names, or null when it did not authenticate. */
    public static function from(mysqli $db, object $authentication): ?self
    {
        if (($authentication->authenticated ?? false) !== true) {
            return null;
        }
        return new self(
            $db,
            (string) ($authentication->auth_type ?? ''),
            isset($authentication->user_id) ? (int) $authentication->user_id : null
        );
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
        if ($this->type !== 'agent_key') {
            return null;
        }
        return [403, [
            'authenticated' => true,
            'message' => 'Agent keys permit reads plus the kept toggle only.',
        ]];
    }
}

?>

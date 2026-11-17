<?php

class RateLimiter {
  // The first 12 bytes of an IPv4-mapped IPv6 address, ::ffff:a.b.c.d.
  private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

  private $db;
  private $table_name = 'rate_limits';

  public function __construct($db) {
    $this->db = $db;
    $this->ensureTable();
  }

  private function ensureTable() {
    $this->db->query("CREATE TABLE IF NOT EXISTS rate_limits (
      id INT AUTO_INCREMENT PRIMARY KEY,
      ip_address VARCHAR(45) NOT NULL,
      endpoint VARCHAR(255) NOT NULL,
      attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ip_endpoint_time (ip_address, endpoint, attempted_at)
    )");
  }

  // The connection peer's attempts are counted under this key. X-Forwarded-For
  // is client-supplied and is not part of it. An IPv6 peer is keyed on its /64
  // network, such as 2001:db8:a:b::/64, so every address in that /64 shares
  // one count. An IPv4-mapped peer (::ffff:a.b.c.d) is keyed as its IPv4
  // address. Any other peer, IPv4 included, is keyed as its address.
  private function connectionPeerKey() {
    $peer = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $packed = inet_pton($peer);
    if ($packed === false || strlen($packed) !== 16) {
      return $peer;
    }
    if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
      return inet_ntop(substr($packed, 12));
    }
    return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
  }

  public function recordAttempt($endpoint) {
    $this->recordAttemptForKey($this->connectionPeerKey(), $endpoint);
  }

  public function isRateLimited($endpoint, $max_attempts, $window_seconds) {
    return $this->isRateLimitedForKey($this->connectionPeerKey(), $endpoint, $max_attempts, $window_seconds);
  }

  public function checkAndRecord($endpoint, $max_attempts = 10, $window_seconds = 900) {
    return $this->checkAndRecordForKey($this->connectionPeerKey(), $endpoint, $max_attempts, $window_seconds);
  }

  // Account-keyed limits use a distinct non-IP prefix in rate_limits.ip_address
  // so per-Account cooldowns persist across requests without altering IP limits.
  // $now is a Y-m-d H:i:s timestamp for deterministic tests; MySQL NOW() when omitted.
  public function checkAndRecordForAccount(int $accountId, string $endpoint, int $max_attempts = 1, int $window_seconds = 900, ?string $now = null): bool {
    return $this->checkAndRecordForKey('account:' . $accountId, $endpoint, $max_attempts, $window_seconds, $now);
  }

  private function recordAttemptForKey(string $key, string $endpoint, ?string $now = null): void {
    $stmt = $this->db->prepare(
      "INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES (?, ?, COALESCE(?, NOW()))"
    );
    $stmt->bind_param("sss", $key, $endpoint, $now);
    $stmt->execute();
    $stmt->close();
  }

  private function isRateLimitedForKey(string $key, string $endpoint, int $max_attempts, int $window_seconds, ?string $now = null): bool {
    // Only this endpoint. Another endpoint's rows stay, even past this window.
    $stmt = $this->db->prepare(
      "DELETE FROM rate_limits WHERE endpoint = ? AND attempted_at < DATE_SUB(COALESCE(?, NOW()), INTERVAL ? SECOND)"
    );
    $stmt->bind_param("ssi", $endpoint, $now, $window_seconds);
    $stmt->execute();
    $stmt->close();

    // Count recent attempts
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) as attempts FROM rate_limits
       WHERE ip_address = ? AND endpoint = ?
       AND attempted_at > DATE_SUB(COALESCE(?, NOW()), INTERVAL ? SECOND)"
    );
    $stmt->bind_param("sssi", $key, $endpoint, $now, $window_seconds);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['attempts'] >= $max_attempts;
  }

  private function checkAndRecordForKey(string $key, string $endpoint, int $max_attempts, int $window_seconds, ?string $now = null): bool {
    if ($this->isRateLimitedForKey($key, $endpoint, $max_attempts, $window_seconds, $now)) {
      return false;
    }
    $this->recordAttemptForKey($key, $endpoint, $now);
    return true;
  }
}

?>

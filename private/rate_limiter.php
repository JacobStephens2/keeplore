<?php

class RateLimiter {
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

  // Connection peer. X-Forwarded-For is client-supplied and is not a rate-limit key.
  private function getClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
  }

  public function recordAttempt($endpoint) {
    $ip = $this->getClientIp();
    $stmt = $this->db->prepare(
      "INSERT INTO rate_limits (ip_address, endpoint, attempted_at) VALUES (?, ?, NOW())"
    );
    $stmt->bind_param("ss", $ip, $endpoint);
    $stmt->execute();
    $stmt->close();
  }

  public function isRateLimited($endpoint, $max_attempts, $window_seconds) {
    $ip = $this->getClientIp();

    // Only this endpoint. Another endpoint's rows stay, even past this window.
    $stmt = $this->db->prepare(
      "DELETE FROM rate_limits WHERE endpoint = ? AND attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $stmt->bind_param("si", $endpoint, $window_seconds);
    $stmt->execute();
    $stmt->close();

    // Count recent attempts
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) as attempts FROM rate_limits
       WHERE ip_address = ? AND endpoint = ?
       AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $stmt->bind_param("ssi", $ip, $endpoint, $window_seconds);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['attempts'] >= $max_attempts;
  }

  public function checkAndRecord($endpoint, $max_attempts = 10, $window_seconds = 900) {
    if ($this->isRateLimited($endpoint, $max_attempts, $window_seconds)) {
      return false;
    }
    $this->recordAttempt($endpoint);
    return true;
  }
}

?>

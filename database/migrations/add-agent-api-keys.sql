-- Per-agent per-user API keys for remote agent HTTP access (spec #10,
-- ticket #18, ADR 0002). Agents authenticate with a Bearer token whose
-- SHA-256 hash is stored here; the plaintext token is shown once at
-- creation and never stored. Revocation sets revoked_at. No foreign keys,
-- following the proposal-outcomes migration convention.

CREATE TABLE IF NOT EXISTS agent_api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  agent_name VARCHAR(100) NOT NULL,
  key_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_agent_api_keys_hash (key_hash),
  KEY idx_agent_api_keys_user (user_id)
);

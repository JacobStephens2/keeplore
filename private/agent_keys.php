<?php

/**
 * Per-agent per-user API keys for remote agent HTTP access (spec #10,
 * ticket #18, ADR 0002).
 *
 * Agents authenticate with a Bearer token. Only the SHA-256 hash is stored;
 * the plaintext token is shown once at creation. A key scopes its holder to
 * one user's collection, uses, and proposals reads plus the kept toggle;
 * every other write rejects agent-key authentication.
 */

function generate_agent_api_token() {
  return 'ak_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function agent_api_key_hash($token) {
  return hash('sha256', (string) $token);
}

function create_agent_key($conn, $user_id, $agent_name) {
  $agent_name = trim((string) $agent_name);
  if ($agent_name === '') {
    return ['error' => 'Agent name cannot be blank.'];
  }
  $token = generate_agent_api_token();
  $hash = agent_api_key_hash($token);
  $stmt = mysqli_prepare($conn, "INSERT INTO agent_api_keys (user_id, agent_name, key_hash) VALUES (?, ?, ?)");
  mysqli_stmt_bind_param($stmt, "iss", $user_id, $agent_name, $hash);
  $ok = mysqli_stmt_execute($stmt);
  $id = $ok ? mysqli_insert_id($conn) : null;
  mysqli_stmt_close($stmt);
  if (!$ok) {
    return ['error' => 'Failed to create agent key.'];
  }
  return ['id' => $id, 'token' => $token];
}

function list_agent_keys($conn, $user_id) {
  $user_id = (int) $user_id;
  $stmt = mysqli_prepare($conn, "SELECT id, agent_name, created_at, last_used_at, revoked_at FROM agent_api_keys WHERE user_id = ? ORDER BY id DESC");
  mysqli_stmt_bind_param($stmt, "i", $user_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $keys = [];
  while ($row = mysqli_fetch_assoc($result)) {
    $keys[] = $row;
  }
  mysqli_stmt_close($stmt);
  return $keys;
}

function revoke_agent_key($conn, $user_id, $key_id) {
  $user_id = (int) $user_id;
  $key_id = (int) $key_id;
  $stmt = mysqli_prepare($conn, "UPDATE agent_api_keys SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1");
  mysqli_stmt_bind_param($stmt, "ii", $key_id, $user_id);
  mysqli_stmt_execute($stmt);
  $affected = mysqli_stmt_affected_rows($stmt);
  mysqli_stmt_close($stmt);
  return $affected === 1;
}

function find_agent_key_by_token($conn, $token) {
  $hash = agent_api_key_hash($token);
  $stmt = mysqli_prepare($conn, "SELECT id, user_id, agent_name FROM agent_api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1");
  mysqli_stmt_bind_param($stmt, "s", $hash);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($result);
  mysqli_stmt_close($stmt);
  if (!$row) {
    return false;
  }
  $touch = mysqli_prepare($conn, "UPDATE agent_api_keys SET last_used_at = NOW() WHERE id = ? LIMIT 1");
  mysqli_stmt_bind_param($touch, "i", $row['id']);
  mysqli_stmt_execute($touch);
  mysqli_stmt_close($touch);
  return $row;
}

/**
 * Scope gate: agent keys may read and flip kept, nothing else. Call from
 * every mutating API endpoint (and any read outside the agent scope).
 * Exits 403 when the caller authenticated with an agent key.
 */
function deny_agent_key_writes($authentication_response) {
  if (is_object($authentication_response)
      && isset($authentication_response->auth_type)
      && $authentication_response->auth_type === 'agent_key') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
      'authenticated' => true,
      'message' => 'Agent keys permit reads plus the kept toggle only.',
    ]);
    exit;
  }
}

/**
 * Overlap-release kept I/O for the agent API: accepts the new is_kept name
 * and the legacy KeptCol alias, returning the resolved 0/1 or null when
 * neither is present. Removed by the Contract ticket (#17).
 */
function resolve_agent_kept_input($body) {
  if (!is_object($body) && !is_array($body)) {
    return null;
  }
  $body = (array) $body;
  if (array_key_exists('is_kept', $body) && $body['is_kept'] !== null && $body['is_kept'] !== '') {
    return normalize_kept_value($body['is_kept']);
  }
  if (array_key_exists('KeptCol', $body) && $body['KeptCol'] !== null && $body['KeptCol'] !== '') {
    return normalize_kept_value($body['KeptCol']);
  }
  return null;
}

/**
 * Overlap-release kept output: returns the row's kept, secondary, and
 * format state under both the new and legacy field names so older clients
 * keep working for exactly one release. Removed by the Contract ticket.
 */
function agent_kept_fields($row) {
  $row = is_object($row) ? get_object_vars($row) : (array) $row;
  $is_kept = artifact_is_kept($row) ? 1 : 0;
  $secondary = artifact_is_in_secondary_collection($row) ? 1 : 0;
  $digital = isset($row['is_digital']) && $row['is_digital'] !== null && $row['is_digital'] !== ''
    ? normalize_kept_value($row['is_digital'])
    : (isset($row['KeptDig']) && $row['KeptDig'] !== null && $row['KeptDig'] !== '' ? normalize_kept_value($row['KeptDig']) : null);
  $physical = isset($row['is_physical']) && $row['is_physical'] !== null && $row['is_physical'] !== ''
    ? normalize_kept_value($row['is_physical'])
    : (isset($row['KeptPhys']) && $row['KeptPhys'] !== null && $row['KeptPhys'] !== '' ? normalize_kept_value($row['KeptPhys']) : null);
  return [
    'is_kept' => $is_kept,
    'KeptCol' => $is_kept,
    'is_in_secondary_collection' => $secondary,
    'InSecondaryCollection' => $secondary === 1 ? 'yes' : null,
    'is_digital' => $digital,
    'KeptDig' => $digital,
    'is_physical' => $physical,
    'KeptPhys' => $physical,
  ];
}

?>

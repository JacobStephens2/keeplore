<?php
  // Per-agent per-user API keys (spec #10, ticket #18, ADR 0002). A key
  // scopes its holder to this user's collection, uses, and proposals reads
  // plus the kept toggle. The plaintext secret is shown once at creation.

  require_once('../../private/initialize.php');
  require_login();
  global $db;

  $user_id = (int) $_SESSION['user_id'];
  $errors = [];
  $new_token = null;
  $new_token_name = '';

  if (is_post_request()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
      $agent_name = trim($_POST['agent_name'] ?? '');
      $created = create_agent_key($db, $user_id, $agent_name);
      if (isset($created['error'])) {
        $errors[] = $created['error'];
      } else {
        $new_token = $created['token'];
        $new_token_name = $agent_name;
        $_SESSION['message'] = 'Agent key created for ' . $agent_name . '.';
      }
    } elseif ($action === 'revoke') {
      $key_id = (int) ($_POST['key_id'] ?? 0);
      if ($key_id > 0 && revoke_agent_key($db, $user_id, $key_id)) {
        $_SESSION['message'] = 'Agent key revoked.';
      } else {
        $errors[] = 'Could not revoke that key.';
      }
    }
  }

  $keys = list_agent_keys($db, $user_id);

  $page_title = 'Agent API keys';
  include(SHARED_PATH . '/header.php');
?>

<main>
  <div class="object">
    <h1>Agent API keys</h1>
    <p class="page-lede">
      Keys let a remote agent read your collection, uses, and proposals, and
      flip kept status. A key can never change anything else. The secret is
      shown once &mdash; store it with the agent, then it cannot be displayed again.
    </p>

    <?php echo display_errors($errors); ?>

    <?php if ($new_token !== null) { ?>
      <div id="message">
        <p><strong>New key for <?php echo h($new_token_name); ?>:</strong></p>
        <p><code><?php echo h($new_token); ?></code></p>
        <p>Send it as an <code>Authorization: Bearer</code> header. This is the only time it is shown.</p>
      </div>
    <?php } ?>

    <h2>Create a key</h2>
    <form method="post" action="<?php echo url_for('/settings/agent-keys.php'); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="create">
      <label for="agent_name">Agent name</label>
      <input type="text" name="agent_name" id="agent_name" required maxlength="100"
        placeholder="e.g. weekly-review">
      <input type="submit" value="Create key">
    </form>

    <h2>Existing keys</h2>
    <?php if (empty($keys)) { ?>
      <p>No agent keys yet.</p>
    <?php } else { ?>
      <table class="list">
        <thead>
          <tr>
            <th>Agent</th>
            <th>Created</th>
            <th>Last used</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($keys as $key) { ?>
            <tr>
              <td><?php echo h($key['agent_name']); ?></td>
              <td><?php echo h($key['created_at']); ?></td>
              <td><?php echo h($key['last_used_at'] ?? 'never'); ?></td>
              <td><?php echo $key['revoked_at'] === null ? 'active' : 'revoked ' . h($key['revoked_at']); ?></td>
              <td>
                <?php if ($key['revoked_at'] === null) { ?>
                  <form method="post" action="<?php echo url_for('/settings/agent-keys.php'); ?>" style="margin:0;">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>">
                    <button type="submit">Revoke</button>
                  </form>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    <?php } ?>

    <a class="back-link" href="<?php echo url_for('/settings/edit.php'); ?>">Back to settings</a>
  </div>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

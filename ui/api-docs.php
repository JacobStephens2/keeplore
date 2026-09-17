<?php
require_once('../private/initialize.php');

$docs = agent_api_docs();

if (isset($_GET['format']) && $_GET['format'] === 'json') {
  header('Content-Type: application/json');
  echo json_encode($docs);
  exit;
}

$document_title = 'Agent API — Keeplore';
$page_title = 'Agent API';
$page_description = 'Public docs for the Keeplore agent HTTP API: Bearer keys, item reads, uses, proposal outcomes, and the kept toggle.';
include(SHARED_PATH . '/header.php');
?>

<main class="api-docs">
  <header class="page-header">
    <p class="section-label">HTTP API</p>
    <h1>Agent API</h1>
    <p class="page-lede">
      Remote agents authenticate with a per-user key and may read a collection,
      its uses, and its proposal outcomes. The only write is flipping kept.
      Mint a key under Settings after you log in.
    </p>
  </header>

  <section class="surface-panel api-docs-panel">
    <h2>Base URL and auth</h2>
    <dl class="api-docs-meta">
      <dt>Base URL</dt>
      <dd><code><?php echo h($docs['base_url']); ?></code></dd>
      <dt>Header</dt>
      <dd><code><?php echo h($docs['auth']['header']); ?></code></dd>
      <dt>Issue a key</dt>
      <dd>
        <a href="<?php echo url_for($docs['auth']['issue_path']); ?>">
          Settings → Agent API keys
        </a>
        (shown once; prefix <code><?php echo h($docs['auth']['token_prefix']); ?></code>)
      </dd>
      <dt>Scope</dt>
      <dd><?php echo h($docs['auth']['scope']); ?></dd>
      <dt>Rate limit</dt>
      <dd><?php echo (int) $docs['rate_limit']['requests']; ?> requests per <?php echo (int) $docs['rate_limit']['window_seconds']; ?> seconds, per IP</dd>
    </dl>
    <p>
      Other writes, and user enumeration, return 403:
      <?php echo h($docs['denied_message']); ?>
    </p>
    <p>
      Machine-readable catalog:
      <a href="<?php echo url_for('/api-docs?format=json'); ?>"><code>/api-docs?format=json</code></a>
      and
      <a href="<?php echo h($docs['base_url']); ?>/"><code><?php echo h($docs['base_url']); ?>/</code></a>.
    </p>
  </section>

  <?php foreach ($docs['endpoints'] as $endpoint) { ?>
    <section class="surface-panel api-docs-panel" id="<?php echo h($endpoint['id']); ?>">
      <p class="section-label"><?php echo h($endpoint['method']); ?></p>
      <h2><code><?php echo h($endpoint['path']); ?></code></h2>
      <p><?php echo h($endpoint['summary']); ?></p>

      <?php if (!empty($endpoint['query_fields'])) { ?>
        <h3>Query</h3>
        <ul>
          <?php foreach ($endpoint['query_fields'] as $field) { ?>
            <li class="bullet"><code><?php echo h($field); ?></code></li>
          <?php } ?>
        </ul>
      <?php } ?>

      <?php if (!empty($endpoint['body_fields'])) { ?>
        <h3>JSON body</h3>
        <ul>
          <?php foreach ($endpoint['body_fields'] as $field) { ?>
            <li class="bullet"><code><?php echo h($field); ?></code></li>
          <?php } ?>
        </ul>
      <?php } ?>

      <?php if (!empty($endpoint['sort_values'])) { ?>
        <h3>Sort</h3>
        <ul>
          <?php foreach ($endpoint['sort_values'] as $value) { ?>
            <li class="bullet"><code><?php echo h($value); ?></code></li>
          <?php } ?>
        </ul>
      <?php } ?>

      <?php if (!empty($endpoint['is_kept_values'])) { ?>
        <p><code>is_kept</code> must be <?php echo h(implode(' or ', $endpoint['is_kept_values'])); ?>.</p>
      <?php } ?>

      <?php if (!empty($endpoint['get_lists_first_page'])) { ?>
        <p>GET with no body also lists page 1 for an agent key.</p>
      <?php } ?>

      <?php if (!empty($endpoint['notes'])) { ?>
        <ul>
          <?php foreach ($endpoint['notes'] as $note) { ?>
            <li class="bullet"><?php echo h($note); ?></li>
          <?php } ?>
        </ul>
      <?php } ?>

      <h3>Example</h3>
      <pre><code><?php echo h(agent_api_curl_example($endpoint, $docs['base_url'])); ?></code></pre>
    </section>
  <?php } ?>

  <section class="surface-panel api-docs-panel">
    <h2>Vocabulary</h2>
    <ul>
      <li class="bullet"><strong>Item</strong> — a tracked entity, including games and other types.</li>
      <li class="bullet"><strong>Kept</strong> — chosen for the primary collection. Independent of secondary collection, physical, and digital.</li>
      <li class="bullet"><strong>Use</strong> — an actual interaction. Restarts time since last use.</li>
      <li class="bullet"><strong>Item proposal</strong> — a suggestion to use an item, recorded with an outcome.</li>
      <li class="bullet"><strong>Explicit decline</strong> — a direct refusal of a proposed item, including “not tonight.”</li>
      <li class="bullet"><strong>Chose something else</strong> — the group selected something else without explicitly declining.</li>
    </ul>
  </section>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

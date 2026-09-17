<?php
  $document_title = 'Keeplore';
  $page_title = 'Keeplore';
  $page_description = 'Know what you own. Use what you keep. Interact-by dates for the items that earn their place.';
  include(SHARED_PATH . '/header.php');
?>

<main class="landing">
  <section class="landing-hero">
    <div class="landing-copy">
      <p class="section-label">Possessions, in use</p>
      <h1>Know what you own. Use what you keep.</h1>
      <p class="landing-lede">
        Keeplore generates interact-by dates so the things you keep stay in use.
        Record interactions, watch what is due, and keep a clear history of item
        proposals. Inspired by
        <a href="https://www.theminimalists.com/ninety/" target="_blank" rel="noopener">The Minimalists' 90/90 Rule</a>.
      </p>
      <div class="landing-actions">
        <a class="prominent-link" href="<?php echo url_for('/register.php'); ?>">Create an account</a>
        <a class="secondary-link" href="<?php echo url_for('/login.php'); ?>">Log in</a>
        <a class="secondary-link" href="<?php echo url_for('/login.php?action=guest'); ?>">Browse as guest</a>
        <a class="secondary-link" href="<?php echo url_for('/api-docs'); ?>">API docs</a>
      </div>
    </div>
    <div class="landing-hero-visual">
      <img
        src="<?php echo url_for('/assets/keeplore.png'); ?>"
        alt="A quiet shelf of well-loved possessions with interact-by dates"
        width="1400"
        height="788"
        loading="eager"
        decoding="async"
      >
    </div>
  </section>

  <section class="landing-features" aria-label="What Keeplore does">
    <article class="landing-feature">
      <p class="section-label">Collection</p>
      <h2>Items that earn their place</h2>
      <p>
        Track games, books, tools, and anything else as items. Kept means you
        chose to keep it in the primary collection. Secondary collection is
        independent overflow. To get rid of is a removal flag, not the opposite
        of kept. Physical and digital are format flags; an item can be both.
      </p>
    </article>
    <article class="landing-feature">
      <p class="section-label">Rhythm</p>
      <h2>Interact-by dates</h2>
      <p>
        Each kept item gets an interact-by date from your interval, 90 days by
        default. Never used: one interval from when you started tracking. After
        a use: two intervals from that use. The queue is what needs attention first.
      </p>
    </article>
    <article class="landing-feature">
      <p class="section-label">History</p>
      <h2>Item proposals</h2>
      <p>
        When you suggest an item and the group refuses, record an explicit
        decline. When they pick something else without refusing, record chose
        something else. Unsuccessful proposals are not uses and do not restart
        time since last use.
      </p>
    </article>
    <article class="landing-feature">
      <p class="section-label">Agents</p>
      <h2>A read API, plus kept</h2>
      <p>
        Mint a per-agent key and let a remote agent read your collection, uses,
        and proposal outcomes. The only write it can make is flipping kept.
        <a href="<?php echo url_for('/api-docs'); ?>">Read the API docs</a>.
      </p>
    </article>
  </section>

  <section class="landing-how">
    <p class="section-label">How it works</p>
    <h2>Three habits, one shelf</h2>
    <ol class="landing-steps">
      <li>
        <p class="landing-step-num">01</p>
        <h3>Add the items you keep</h3>
        <p>Give each item a type, an interval if it needs one, and a kept decision.</p>
      </li>
      <li>
        <p class="landing-step-num">02</p>
        <h3>Record uses as you go</h3>
        <p>A use is an actual interaction. That is what moves the interact-by date.</p>
      </li>
      <li>
        <p class="landing-step-num">03</p>
        <h3>Review what is due</h3>
        <p>Snooze, keep, or mark to get rid of. Proposal history informs the call; it does not replace a use.</p>
      </li>
    </ol>
  </section>

  <section class="landing-api">
    <p class="section-label">For agents</p>
    <h2>HTTP access, scoped to one account</h2>
    <p>
      Keys are minted under Settings after you log in, shown once, and sent as
      <code>Authorization: Bearer</code>. They always read their own user's
      items. Rate limit: 60 requests per minute.
    </p>
    <div class="landing-actions">
      <a class="prominent-link" href="<?php echo url_for('/api-docs'); ?>">API docs</a>
      <a class="secondary-link" href="<?php echo url_for('/api-docs?format=json'); ?>">Catalog as JSON</a>
    </div>
  </section>

  <p class="menu-about">
    Built by
    <a href="https://jacobstephens.net" target="_blank" rel="noopener">Jacob Stephens</a>.
  </p>
</main>

<?php include(SHARED_PATH . '/footer.php'); ?>

<?php
  if ( ! isset($page_title) ) { $page_title = 'Keeplore'; }
  if ( ! isset($page_description) ) {
    $page_description = 'Know what you own. Use what you keep. Interact-by dates for the items that earn their place.';
  }
  if ( ! isset($document_title) ) {
    $document_title = $page_title === 'Keeplore' ? 'Keeplore' : ($page_title . ' - Keeplore');
  }
  $keeplore_is_public = !is_logged_in() && !is_guest();
  $keeplore_body_class = $keeplore_is_public ? 'public-mode' : (is_guest() ? 'guest-mode' : 'signed-in-mode');
?>

<!DOCTYPE html>

<html lang="en">
  <head>
    
    <title>
      <?php echo h($document_title); ?>
    </title>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#30395c">
    <meta name="description" content="<?php echo h($page_description); ?>">
    <meta name="application-name" content="Keeplore">
    <meta name="apple-mobile-web-app-title" content="Keeplore">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <?php
      $keeplore_host = (defined('DOMAIN') && DOMAIN !== '')
        ? DOMAIN
        : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'keeplore.app');
      $keeplore_og_image = 'https://' . $keeplore_host . '/assets/keeplore.png';
    ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keeplore">
    <meta property="og:title" content="<?php echo h($document_title); ?>">
    <meta property="og:description" content="<?php echo h($page_description); ?>">
    <meta property="og:image" content="<?php echo h($keeplore_og_image); ?>">
    <meta property="og:image:width" content="1400">
    <meta property="og:image:height" content="788">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?php echo h($keeplore_og_image); ?>">

    <link rel="icon" type="image/svg+xml" href="<?php echo url_for('/assets/logo.svg'); ?>?v=5">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo url_for('/assets/favicon-32.png'); ?>?v=5">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo url_for('/assets/icon-192x192.png'); ?>?v=5">
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo url_for('/favicon.ico'); ?>?v=5">
    <link rel="manifest" href="<?php echo url_for('manifest.json') ?>">
    <link rel="apple-touch-icon" href="<?php echo url_for('/assets/icon-192x192.png'); ?>?v=5">

    <script>
      // Display mode (issue #7): set data-theme="light|dark" before first
      // paint so the correct tokens apply with no flash. Preference lives in
      // localStorage `keeplore-theme`; `system` (default) follows the OS.
      // The #theme-switcher select (values from theme_options()) is the
      // source of truth for the vocabulary; ui/shared/js/theme.js wires it.
      (function() {
        var KEY = 'keeplore-theme';
        var stored = null;
        try { stored = window.localStorage.getItem(KEY); } catch (e) { stored = null; }
        var pref = (stored === 'light' || stored === 'dark' || stored === 'system') ? stored : 'system';
        var effective = pref;
        if (pref === 'system') {
          try {
            effective = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
          } catch (e) { effective = 'light'; }
        }
        document.documentElement.setAttribute('data-theme', effective);
        document.documentElement.setAttribute('data-theme-pref', pref);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', effective === 'dark' ? '#0c1222' : '#30395c'); }
      })();
    </script>
    <link rel="stylesheet" media="all" href="../../style.css?v=42" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-P3N6C9C37N"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-P3N6C9C37N');
    </script>

  </head>

  <body class="<?php echo h($keeplore_body_class); ?>">
    <header class="site-header">
      <div class="site-header-inner">
        <div class="site-brand">
          <a class="header-link" href="/">
            <img class="site-logo" src="<?php echo url_for('/assets/icon-192x192.png'); ?>?v=5" width="36" height="36" alt="" aria-hidden="true">
            <span class="site-wordmark">Keeplore</span>
          </a>
          <p class="site-tagline">Know what you own. Use what you keep.</p>
        </div>

        <div class="site-status">
          <?php
          if(isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == true && isset($_SESSION['username'])) {
            ?>
            <a class="site-status-link desktop-only" href="<?php echo url_for('/settings/edit'); ?>">
              <?php echo h($_SESSION['username']); ?>
            </a>
            <?php
          } elseif (is_guest()) {
            ?>
            <span class="site-status-pill desktop-only">Guest</span>
            <?php
          }
          ?>
          <label class="theme-switcher">
            <span class="sr-only">Display mode</span>
            <select id="theme-switcher" data-default="<?php echo h(theme_default()); ?>" aria-label="Display mode">
              <?php foreach (theme_options() as $theme_value => $theme_label) { ?>
                <option value="<?php echo h($theme_value); ?>"><?php echo h($theme_label); ?></option>
              <?php } ?>
            </select>
          </label>
          <button class="burger-btn" aria-label="Toggle menu" aria-expanded="false">
            <span class="burger-icon"></span>
          </button>
        </div>
      </div>
    </header>

    <nav class="site-nav hideOnPrint" aria-label="Main">
      <div class="site-nav-inner">
        <?php
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == true) {
          ?>
          <div class="nav-group nav-group-primary" aria-label="Primary">
            <a class="nav-link nav-link-primary" href="<?php echo url_for('/artifacts/useby'); ?>">Interact&nbsp;By&nbsp;Date</a>
            <a class="nav-link nav-link-primary" href="<?php echo url_for('/uses/record-new'); ?>">Record&nbsp;Interaction</a>
          </div>

          <div class="nav-group nav-group-secondary" aria-label="Browse">
            <a class="nav-link" href="<?php echo url_for('/uses/interactions'); ?>">Interactions</a>
            <a class="nav-link" href="<?php echo url_for('/artifacts'); ?>">Items</a>
            <a class="nav-link" href="<?php echo url_for('/artifacts/to-get-rid-of'); ?>">To&nbsp;Get&nbsp;Rid&nbsp;Of</a>
            <a class="nav-link" href="<?php echo url_for('/analysis'); ?>">Analysis</a>
          </div>

          <div class="nav-group nav-group-more">
            <details class="nav-more">
              <summary class="nav-link nav-more-summary" aria-haspopup="menu">More</summary>
              <div class="nav-more-panel" role="menu" aria-label="More destinations">
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/types'); ?>">Types</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/users'); ?>">Users</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/api-docs'); ?>">API</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/support'); ?>">Support</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/settings/edit'); ?>">Settings</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('logout'); ?>">Logout</a>
              </div>
            </details>
          </div>
          <?php

        } elseif (is_guest()) {
          ?>
          <div class="nav-group nav-group-primary" aria-label="Primary">
            <a class="nav-link nav-link-primary" href="<?php echo url_for('/artifacts/useby'); ?>">Interact&nbsp;By&nbsp;Date</a>
          </div>

          <div class="nav-group nav-group-secondary" aria-label="Browse">
            <a class="nav-link" href="<?php echo url_for('/uses/interactions'); ?>">Interactions</a>
            <a class="nav-link" href="<?php echo url_for('/artifacts'); ?>">Items</a>
            <a class="nav-link" href="<?php echo url_for('/artifacts/to-get-rid-of'); ?>">To&nbsp;Get&nbsp;Rid&nbsp;Of</a>
            <a class="nav-link" href="<?php echo url_for('/analysis'); ?>">Analysis</a>
          </div>

          <div class="nav-group nav-group-more">
            <details class="nav-more">
              <summary class="nav-link nav-more-summary" aria-haspopup="menu">More</summary>
              <div class="nav-more-panel" role="menu" aria-label="More destinations">
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/types'); ?>">Types</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/api-docs'); ?>">API</a>
                <a class="nav-link" role="menuitem" href="<?php echo url_for('/login.php?action=logout'); ?>">Exit&nbsp;Guest&nbsp;Mode</a>
              </div>
            </details>
          </div>
          <?php
        } else {
          ?>
          <div class="nav-group nav-group-primary" aria-label="Primary">
            <a class="nav-link nav-link-primary" href="<?php echo url_for('/register.php'); ?>">Create&nbsp;account</a>
            <a class="nav-link nav-link-primary" href="<?php echo url_for('/login.php'); ?>">Log&nbsp;in</a>
          </div>

          <div class="nav-group nav-group-secondary" aria-label="Browse">
            <a class="nav-link" href="<?php echo url_for('/api-docs'); ?>">API</a>
            <a class="nav-link" href="<?php echo url_for('/login.php?action=guest'); ?>">Browse&nbsp;as&nbsp;guest</a>
          </div>
          <?php
        }
      ?>
      </div>
    </nav>

    <?php if (is_guest()) { ?>
      <div class="guest-banner">
        You are browsing as a guest.
        <a href="<?php echo url_for('/register.php'); ?>">Create an account</a> to track your own items,
        or <a href="<?php echo url_for('/login.php?action=logout'); ?>">exit guest mode</a>.
      </div>
    <?php } ?>

    <script>
      (function() {
        var btn = document.querySelector('.burger-btn');
        var nav = document.querySelector('.site-nav');
        var header = document.querySelector('.site-header');
        var more = document.querySelector('.nav-more');
        var moreSummary = more ? more.querySelector('.nav-more-summary') : null;

        function updateHeaderHeight() {
          if (header) {
            document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
          }
        }

        function closeMore(returnFocus) {
          if (!more || !more.open) return;
          more.removeAttribute('open');
          if (returnFocus && moreSummary) {
            moreSummary.focus();
          }
        }

        function closeMobileNav(returnFocus) {
          if (!nav || !nav.classList.contains('nav-open')) return;
          nav.classList.remove('nav-open');
          if (btn) {
            btn.setAttribute('aria-expanded', 'false');
            if (returnFocus) btn.focus();
          }
        }

        updateHeaderHeight();
        window.addEventListener('resize', updateHeaderHeight);

        if (btn && nav) {
          btn.addEventListener('click', function() {
            updateHeaderHeight();
            var open = nav.classList.toggle('nav-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open) {
              closeMore(false);
            }
          });
        }

        // Close the More panel when clicking outside (desktop).
        document.addEventListener('click', function(e) {
          if (!more || !more.open) return;
          if (!more.contains(e.target)) {
            closeMore(false);
          }
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') {
            if (more && more.open) {
              closeMore(true);
              e.preventDefault();
              return;
            }
            if (nav && nav.classList.contains('nav-open')) {
              closeMobileNav(true);
              e.preventDefault();
            }
          }
        });

        // When More opens, move focus to the first menu link for keyboard users.
        if (more) {
          more.addEventListener('toggle', function() {
            if (!more.open) return;
            var first = more.querySelector('.nav-more-panel a');
            if (first) first.focus();
          });
        }
      })();
    </script>

    <?php echo display_session_message(); ?>

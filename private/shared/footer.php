    <footer class="site-footer">
      <div class="site-footer-inner">
        <div>
          <p class="footer-label">Keeplore</p>
          <h2>Know what you own. Use what you keep.</h2>
        </div>
        <p class="footer-meta">
          <a href="<?php echo url_for('/api-docs'); ?>">API</a>
          &copy; <?php echo date('Y'); ?> <a href="https://resume.jacobstephens.net" target="_blank" rel="noopener">Jacob Stephens</a>
        </p>
      </div>
    </footer>

    <script>
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js');
      }
    </script>
    <script src="/native-notifications.js?v=2" defer></script>
    <script src="/shared/js/theme.js?v=1" defer></script>
  </body>
</html>

<?php db_disconnect($db); ?>

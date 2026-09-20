<?php

class Cache {

  private $cache_dir;
  private $writable;

  public function __construct($cache_dir = null) {
    $this->cache_dir = rtrim($cache_dir ?? (PROJECT_PATH . '/cache'), '/') . '/';
    $this->writable = $this->ensureDirectory();
  }

  /**
   * Production Releases are chmod a-w. mkdir() there becomes an Apache
   * warning on every request. Skip the cache rather than warn.
   */
  private function ensureDirectory() {
    if (is_dir($this->cache_dir)) {
      return is_writable($this->cache_dir);
    }
    $parent = dirname($this->cache_dir);
    if (!is_dir($parent) || !is_writable($parent)) {
      return false;
    }
    return mkdir($this->cache_dir, 0755, true) || is_dir($this->cache_dir);
  }

  /**
   * Get a cached value by key.
   * Returns null if the entry is missing or expired.
   */
  public function get($key) {
    if (!$this->writable) {
      return null;
    }
    $file = $this->file_path($key);
    if (!file_exists($file)) {
      return null;
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
      return null;
    }

    $cached = unserialize($contents);
    if ($cached === false) {
      return null;
    }

    // Check expiration
    if (time() > $cached['expires_at']) {
      $this->delete($key);
      return null;
    }

    return $cached['value'];
  }

  /**
   * Cache a value with a TTL in seconds (default 5 minutes).
   */
  public function set($key, $value, $ttl = 300) {
    if (!$this->writable) {
      return;
    }
    $file = $this->file_path($key);
    $cached = [
      'expires_at' => time() + $ttl,
      'value' => $value,
    ];
    file_put_contents($file, serialize($cached));
  }

  /**
   * Remove a cached entry by key.
   */
  public function delete($key) {
    if (!$this->writable) {
      return;
    }
    $file = $this->file_path($key);
    if (file_exists($file)) {
      unlink($file);
    }
  }

  /**
   * Clear all cache entries.
   */
  public function clear() {
    if (!$this->writable) {
      return;
    }
    $files = glob($this->cache_dir . '*.cache');
    if ($files !== false) {
      foreach ($files as $file) {
        unlink($file);
      }
    }
  }

  /**
   * Get from cache or execute callback, cache result, and return.
   */
  public function remember($key, $ttl, $callback) {
    $value = $this->get($key);
    if ($value !== null) {
      return $value;
    }

    $value = call_user_func($callback);
    $this->set($key, $value, $ttl);
    return $value;
  }

  /**
   * Generate a filesystem-safe path for a cache key.
   */
  private function file_path($key) {
    return $this->cache_dir . md5($key) . '.cache';
  }

}

?>

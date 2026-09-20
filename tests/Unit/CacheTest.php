<?php

namespace Tests\Unit;

use Cache;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private Cache $cache;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = PROJECT_PATH . '/cache/';
        $this->cache = new Cache();
    }

    protected function tearDown(): void
    {
        // Clean up all cache files after each test
        $this->cache->clear();
    }

    // -----------------------------------------------------------------
    // set() and get()
    // -----------------------------------------------------------------

    public function test_set_and_get_string(): void
    {
        $this->cache->set('greeting', 'hello world');
        $this->assertSame('hello world', $this->cache->get('greeting'));
    }

    public function test_set_and_get_array(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $this->cache->set('item', $data);
        $this->assertSame($data, $this->cache->get('item'));
    }

    public function test_set_and_get_integer(): void
    {
        $this->cache->set('count', 42);
        $this->assertSame(42, $this->cache->get('count'));
    }

    public function test_get_missing_key_returns_null(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->cache->set('key', 'first');
        $this->cache->set('key', 'second');
        $this->assertSame('second', $this->cache->get('key'));
    }

    // -----------------------------------------------------------------
    // TTL expiry
    // -----------------------------------------------------------------

    public function test_expired_entry_returns_null(): void
    {
        $this->cache->set('short_lived', 'data', 1);
        // Confirm it is readable right away
        $this->assertSame('data', $this->cache->get('short_lived'));
        // Wait for it to expire
        sleep(2);
        $this->assertNull($this->cache->get('short_lived'));
    }

    // -----------------------------------------------------------------
    // delete()
    // -----------------------------------------------------------------

    public function test_delete_removes_entry(): void
    {
        $this->cache->set('temp', 'value');
        $this->assertSame('value', $this->cache->get('temp'));

        $this->cache->delete('temp');
        $this->assertNull($this->cache->get('temp'));
    }

    public function test_delete_nonexistent_key_does_not_error(): void
    {
        // Should not throw
        $this->cache->delete('does_not_exist');
        $this->assertTrue(true); // assertion to confirm we got here
    }

    // -----------------------------------------------------------------
    // clear()
    // -----------------------------------------------------------------

    public function test_clear_removes_all_entries(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->set('c', 3);

        $this->cache->clear();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
        $this->assertNull($this->cache->get('c'));
    }

    // -----------------------------------------------------------------
    // remember()
    // -----------------------------------------------------------------

    public function test_remember_executes_callback_on_miss(): void
    {
        $callCount = 0;
        $result = $this->cache->remember('computed', 300, function () use (&$callCount) {
            $callCount++;
            return 'expensive result';
        });

        $this->assertSame('expensive result', $result);
        $this->assertSame(1, $callCount);
    }

    public function test_remember_returns_cached_on_hit(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'computed';
        };

        // First call: cache miss, callback runs
        $this->cache->remember('key', 300, $callback);
        $this->assertSame(1, $callCount);

        // Second call: cache hit, callback should NOT run
        $result = $this->cache->remember('key', 300, $callback);
        $this->assertSame('computed', $result);
        $this->assertSame(1, $callCount);
    }

    // -----------------------------------------------------------------
    // Cache directory
    // -----------------------------------------------------------------

    public function test_cache_directory_is_created(): void
    {
        $this->assertDirectoryExists($this->cacheDir);
    }

    public function test_read_only_parent_does_not_warn_and_remember_still_returns(): void
    {
        $parent = sys_get_temp_dir() . '/keeplore-cache-ro-' . bin2hex(random_bytes(4));
        mkdir($parent, 0755);
        chmod($parent, 0555);
        $cacheDir = $parent . '/cache/';
        $warnings = [];
        set_error_handler(static function ($severity, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        });

        try {
            if (is_writable($parent)) {
                $this->markTestSkipped('Parent stayed writable; chmod 0555 is not enough under this user.');
            }
            $cache = new Cache($cacheDir);
            $value = $cache->remember('k', 60, static function () {
                return 'computed';
            });
            $this->assertSame('computed', $value);
            $this->assertNull($cache->get('k'));
            $cache->set('direct', 'stored');
            $this->assertNull($cache->get('direct'));
            $this->assertDirectoryDoesNotExist($cacheDir);
            $this->assertSame([], $warnings);
        } finally {
            restore_error_handler();
            chmod($parent, 0755);
            if (is_dir($cacheDir)) {
                rmdir($cacheDir);
            }
            rmdir($parent);
        }
    }
}

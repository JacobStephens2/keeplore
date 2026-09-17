<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once PROJECT_PATH . '/private/dev_router.php';

class DevRouterTest extends TestCase
{
    private string $docroot;

    protected function setUp(): void
    {
        $this->docroot = sys_get_temp_dir() . '/keeplore_router_' . bin2hex(random_bytes(4));
        mkdir($this->docroot . '/assets', 0777, true);
        file_put_contents($this->docroot . '/index.php', '<?php echo "home";');
        file_put_contents($this->docroot . '/api-docs.php', '<?php echo "docs";');
        file_put_contents($this->docroot . '/login.php', '<?php echo "login";');
        file_put_contents($this->docroot . '/style.css', 'body{}');
        file_put_contents($this->docroot . '/assets/keeplore.png', 'png');
    }

    protected function tearDown(): void
    {
        foreach (['/assets/keeplore.png', '/style.css', '/login.php', '/api-docs.php', '/index.php'] as $file) {
            @unlink($this->docroot . $file);
        }
        @rmdir($this->docroot . '/assets');
        @rmdir($this->docroot);
    }

    public function test_root_serves_index(): void
    {
        $this->assertSame(
            $this->docroot . '/index.php',
            keeplore_ui_router_script('/', $this->docroot)
        );
    }

    public function test_extensionless_path_maps_to_php(): void
    {
        $this->assertSame(
            $this->docroot . '/api-docs.php',
            keeplore_ui_router_script('/api-docs', $this->docroot)
        );
        $this->assertSame(
            $this->docroot . '/login.php',
            keeplore_ui_router_script('/login?action=guest', $this->docroot)
        );
    }

    public function test_nested_extensionless_path_maps_to_php(): void
    {
        mkdir($this->docroot . '/settings', 0777, true);
        file_put_contents($this->docroot . '/settings/agent-keys.php', '<?php');
        $this->assertSame(
            $this->docroot . '/settings/agent-keys.php',
            keeplore_ui_router_script('/settings/agent-keys', $this->docroot)
        );
        unlink($this->docroot . '/settings/agent-keys.php');
        rmdir($this->docroot . '/settings');
    }

    public function test_existing_static_files_are_left_to_the_server(): void
    {
        $this->assertFalse(keeplore_ui_router_script('/style.css', $this->docroot));
        $this->assertFalse(keeplore_ui_router_script('/assets/keeplore.png', $this->docroot));
        $this->assertFalse(keeplore_ui_router_script('/api-docs.php', $this->docroot));
    }

    public function test_unknown_paths_are_not_found(): void
    {
        $this->assertNull(keeplore_ui_router_script('/does-not-exist', $this->docroot));
    }
}

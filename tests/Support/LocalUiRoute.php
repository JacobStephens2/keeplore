<?php

namespace Tests\Support;

require_once __DIR__ . '/LocalPhpServer.php';

/** Run a real UI route with the application bootstrap and a disposable database. */
final class LocalUiRoute
{
    /** An open PHP block starting with <?php runs before the route on each request. */
    public static function run(string $route, \mysqli $db, string $sessionBootstrapPhp, callable $exercise): void
    {
        $root = sys_get_temp_dir() . '/keeplore-ui-' . bin2hex(random_bytes(6));
        $routeDirectory = $root . '/ui' . dirname($route);
        mkdir($routeDirectory, 0777, true);
        mkdir($root . '/private');
        $server = null;
        try {
            // Keep the actual bootstrap and dependencies, with configuration
            // isolated from the checkout.
            copy(PRIVATE_PATH . '/initialize.php', $root . '/private/initialize.php');
            foreach (glob(PRIVATE_PATH . '/*.php') as $source) {
                $file = basename($source);
                if (!in_array($file, ['initialize.php', 'environment_variables.php'], true)) {
                    symlink($source, $root . '/private/' . $file);
                }
            }
            foreach (['classes', 'shared'] as $directory) {
                symlink(PRIVATE_PATH . '/' . $directory, $root . '/private/' . $directory);
            }
            symlink(PROJECT_PATH . '/vendor', $root . '/private/vendor');
            $config = [
                'DB_SERVER' => getenv('KEEPLORE_TEST_DB_HOST'),
                'DB_USER' => getenv('KEEPLORE_TEST_DB_USER') ?: 'root',
                'DB_PASS' => getenv('KEEPLORE_TEST_DB_PASSWORD') ?: '',
                'DB_NAME' => $db->query('SELECT DATABASE()')->fetch_row()[0],
                'DB_PORT' => (int) (getenv('KEEPLORE_TEST_DB_PORT') ?: 3306),
                'COOKIE_SECURE' => false,
                'ARTIFACTS_DOMAIN' => '',
                'API_ORIGIN' => '127.0.0.1',
            ];
            $environment = '<?php foreach (' . var_export($config, true) . ' as $name => $value) define($name, $value);';
            file_put_contents($root . '/private/environment_variables.php', $environment);
            $server = new LocalPhpServer($sessionBootstrapPhp . "\nchdir(" . var_export($routeDirectory, true) . ');'
                . 'require ' . var_export(PROJECT_PATH . '/ui' . $route, true) . ';');
            $exercise($server);
        } finally {
            $server?->stop();
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($root);
        }
    }
}

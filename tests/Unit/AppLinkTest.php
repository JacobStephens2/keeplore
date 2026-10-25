<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: AppLink.urlToLoad.
 *
 * An App Link loads only when its scheme is https and its host is
 * keeplore.app. The loaded URL is that https origin plus the link's
 * path, query, and fragment. MainActivity is the adapter that asks for
 * this URL and loads nothing else.
 */
final class AppLinkTest extends TestCase
{
    private static string $classpath;

    public static function setUpBeforeClass(): void
    {
        self::$classpath = sys_get_temp_dir() . '/keeplore-app-link-' . getmypid();
        if (!is_dir(self::$classpath) && !mkdir(self::$classpath, 0700, true) && !is_dir(self::$classpath)) {
            throw new \RuntimeException('could not create ' . self::$classpath);
        }

        $sources = [
            PROJECT_PATH . '/capacitor/android/app/src/main/java/app/keeplore/AppLink.java',
            PROJECT_PATH . '/capacitor/android/app/src/test/java/app/keeplore/AppLinkTest.java',
        ];
        $command = 'javac --release 17 -d ' . escapeshellarg(self::$classpath);
        foreach ($sources as $source) {
            $command .= ' ' . escapeshellarg($source);
        }
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(implode("\n", $output));
        }
    }

    public function test_an_https_app_link_on_keeplore_app_loads(): void
    {
        $this->assertSame(
            'https://keeplore.app/uses/record-new?artifact_id=30',
            $this->urlToLoad('https', 'keeplore.app', '/uses/record-new', 'artifact_id=30', null)
        );
    }

    public function test_a_javascript_app_link_does_not_load(): void
    {
        $this->assertNull($this->urlToLoad('javascript', 'keeplore.app', '/%0Aalert(1)', null, null));
    }

    public function test_an_http_app_link_does_not_load(): void
    {
        $this->assertNull($this->urlToLoad('http', 'keeplore.app', '/uses/record-new', 'artifact_id=30', null));
    }

    public function test_an_https_link_on_another_host_does_not_load(): void
    {
        $this->assertNull($this->urlToLoad('https', 'example.invalid', '/', null, null));
        $this->assertNull($this->urlToLoad('https', 'keeplore.app.evil.com', '/', null, null));
        $this->assertNull($this->urlToLoad('https', 'api.keeplore.app', '/artifacts.php', null, null));
    }

    public function test_scheme_and_host_match_ignoring_case(): void
    {
        $this->assertSame(
            'https://keeplore.app/artifacts/snooze.php?artifact_id=30&return_to=useby#done',
            $this->urlToLoad('HTTPS', 'Keeplore.APP', '/artifacts/snooze.php', 'artifact_id=30&return_to=useby', 'done')
        );
    }

    public function test_a_path_that_would_change_the_host_does_not_load(): void
    {
        $this->assertNull($this->urlToLoad('https', 'keeplore.app', '@evil.com', null, null));
    }

    public function test_a_link_without_a_scheme_or_host_does_not_load(): void
    {
        $this->assertNull($this->urlToLoad(null, 'keeplore.app', '/', null, null));
        $this->assertNull($this->urlToLoad('https', null, '/', null, null));
    }

    public function test_the_origin_itself_loads(): void
    {
        $this->assertSame('https://keeplore.app', $this->urlToLoad('https', 'keeplore.app', null, null, null));
        $this->assertSame('https://keeplore.app/', $this->urlToLoad('https', 'keeplore.app', '/', null, null));
    }

    public function test_main_activity_loads_the_app_link_url(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/capacitor/android/app/src/main/java/app/keeplore/MainActivity.java');

        $this->assertStringContainsString('AppLink.urlToLoad(', $source);
        $this->assertStringContainsString('data.getScheme()', $source);
        $this->assertStringContainsString('data.getHost()', $source);
        $this->assertStringContainsString('loadUrl(url)', $source);
        $this->assertStringNotContainsString('data.toString()', $source);
    }

    private function urlToLoad(?string $scheme, ?string $host, ?string $path, ?string $query, ?string $fragment): ?string
    {
        $command = 'java -cp ' . escapeshellarg(self::$classpath) . ' app.keeplore.AppLinkTest';
        foreach ([$scheme, $host, $path, $query, $fragment] as $part) {
            $command .= ' ' . escapeshellarg($part === null ? '-' : base64_encode($part));
        }
        exec($command . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $printed = implode("\n", $output);
        if ($printed === '-') {
            return null;
        }
        $decoded = base64_decode($printed, true);
        $this->assertNotFalse($decoded);

        return $decoded;
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: publicEnvironmentVariables.js API_ORIGIN.
 *
 * The UI's JS modules call the API host the server configured (header.php
 * prints API_ORIGIN into a meta tag), so staging.keeplore.app talks to
 * api.staging.keeplore.app instead of production. Every script that calls
 * the API takes its host from this one module.
 */
class ApiOriginTest extends TestCase
{
    private const MODULE = PROJECT_PATH . '/ui/uses/modules/publicEnvironmentVariables.js';

    public function test_api_origin_reads_the_server_meta_tag(): void
    {
        $this->assertSame('api.staging.keeplore.app', $this->apiOrigin('api.staging.keeplore.app'));
    }

    public function test_api_origin_falls_back_to_production_without_the_meta_tag(): void
    {
        $this->assertSame('api.keeplore.app', $this->apiOrigin(null));
    }

    public function test_api_base_is_the_https_url_of_the_api_origin(): void
    {
        $url = json_encode('file://' . self::MODULE);
        $this->assertSame('https://api.staging.keeplore.app', $this->node('api.staging.keeplore.app', <<<JS
const { API_BASE } = await import({$url});
process.stdout.write(API_BASE);
JS));
    }

    public function test_native_notifications_fetch_from_the_meta_tag_host(): void
    {
        $this->assertSame(
            'https://api.staging.keeplore.app/upcoming-interactions.php',
            $this->nativeNotificationsUrl('api.staging.keeplore.app')
        );
    }

    public function test_native_notifications_fall_back_to_production_without_the_meta_tag(): void
    {
        $this->assertSame(
            'https://api.keeplore.app/upcoming-interactions.php',
            $this->nativeNotificationsUrl(null)
        );
    }

    public function test_footer_loads_native_notifications_as_a_module(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/private/shared/footer.php');

        $this->assertMatchesRegularExpression('#<script type="module" src="/native-notifications\.js\?v=\d+"></script>#', $source);
    }

    public function test_api_client_requests_the_meta_tag_host(): void
    {
        $this->assertSame('https://api.staging.keeplore.app/types.php', $this->apiClientUrl('api.staging.keeplore.app'));
    }

    public function test_api_client_falls_back_to_production_without_the_meta_tag(): void
    {
        $this->assertSame('https://api.keeplore.app/types.php', $this->apiClientUrl(null));
    }

    public function test_only_the_module_names_the_production_api_host(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PROJECT_PATH . '/ui', \FilesystemIterator::SKIP_DOTS));
        $naming = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'js' && str_contains((string) file_get_contents($file->getPathname()), 'api.keeplore.app')) {
                $naming[] = substr($file->getPathname(), strlen(PROJECT_PATH));
            }
        }

        $this->assertSame(['/ui/uses/modules/publicEnvironmentVariables.js'], $naming);
    }

    public function test_quick_record_popup_searches_people_on_the_configured_api_host(): void
    {
        $partial = (string) file_get_contents(PROJECT_PATH . '/private/shared/quick_record_popup.php');
        $module = (string) file_get_contents(PROJECT_PATH . '/ui/shared/js/quick-record.js');

        $this->assertStringContainsString("data-people-search-url=\"<?php echo h('https://' . API_ORIGIN . '/users.php'); ?>\"", $partial);
        $this->assertStringNotContainsString('window.location.host', $module);
    }

    public function test_header_prints_the_api_origin_meta_tag(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/private/shared/header.php');

        $this->assertStringContainsString(
            '<meta name="keeplore-api-origin" content="<?php echo h(API_ORIGIN); ?>">',
            $source
        );
    }

    public function test_api_allows_the_ui_origin_that_matches_its_own_host(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/api/.htaccess');

        $this->assertStringNotContainsString('Access-Control-Allow-Origin "https://keeplore.app"', $source);
        $this->assertMatchesRegularExpression('/SetEnvIf Host "\^api\\\\\.\(\.\+\)\$" KEEPLORE_UI_ORIGIN=https:\/\/\$1/', $source);
        $this->assertStringContainsString('Header set Access-Control-Allow-Origin "%{KEEPLORE_UI_ORIGIN}e" env=KEEPLORE_UI_ORIGIN', $source);
    }

    private function apiOrigin(?string $metaContent): string
    {
        $url = json_encode('file://' . self::MODULE);
        return $this->node($metaContent, <<<JS
const { API_ORIGIN } = await import({$url});
process.stdout.write(API_ORIGIN);
JS);
    }

    /** The URL native-notifications.js fetches inside the native app. */
    private function nativeNotificationsUrl(?string $metaContent): string
    {
        $url = json_encode('file://' . PROJECT_PATH . '/ui/native-notifications.js');
        return $this->node($metaContent, <<<JS
let fetched;
document.readyState = 'complete';
globalThis.fetch = async (url) => { fetched = url; return { status: 401 }; };
globalThis.window = {
  Capacitor: {
    isNativePlatform: () => true,
    Plugins: { LocalNotifications: { addListener: async () => ({ remove() {} }) } },
  },
};
await import({$url});
await new Promise((resolve) => setTimeout(resolve, 0));
process.stdout.write(String(fetched));
JS);
    }

    /** The URL api-client.js requests for a call. */
    private function apiClientUrl(?string $metaContent): string
    {
        $url = json_encode('file://' . PROJECT_PATH . '/ui/shared/js/api-client.js');
        return $this->node($metaContent, <<<JS
let fetched;
globalThis.fetch = async (url) => { fetched = url; return { status: 200, ok: true, json: async () => ({}) }; };
const { default: ApiClient } = await import({$url});
await ApiClient.getTypes();
process.stdout.write(String(fetched));
JS);
    }

    /** Runs $body as an ES module under a fake document holding the meta tag. */
    private function node(?string $metaContent, string $body): string
    {
        $meta = json_encode($metaContent);
        $script = <<<JS
const content = {$meta};
globalThis.document = {
  querySelector: (sel) => sel === 'meta[name="keeplore-api-origin"]' && content !== null
    ? { content: content }
    : null,
};
{$body}
JS;

        $cmd = 'node --input-type=module -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);
        return $raw;
    }
}

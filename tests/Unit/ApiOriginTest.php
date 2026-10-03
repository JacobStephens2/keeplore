<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: publicEnvironmentVariables.js API_ORIGIN.
 *
 * The UI's JS modules call the API host the server configured (header.php
 * prints API_ORIGIN into a meta tag), so staging.keeplore.app talks to
 * api.staging.keeplore.app instead of production.
 */
class ApiOriginTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function modules(): array
    {
        return [
            'uses' => [PROJECT_PATH . '/ui/uses/publicEnvironmentVariables.js'],
            'uses/modules' => [PROJECT_PATH . '/ui/uses/modules/publicEnvironmentVariables.js'],
        ];
    }

    /** @dataProvider modules */
    public function test_api_origin_reads_the_server_meta_tag(string $module): void
    {
        $this->assertSame('api.staging.keeplore.app', $this->apiOrigin($module, 'api.staging.keeplore.app'));
    }

    /** @dataProvider modules */
    public function test_api_origin_falls_back_to_production_without_the_meta_tag(string $module): void
    {
        $this->assertSame('api.keeplore.app', $this->apiOrigin($module, null));
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
        $this->assertStringContainsString('Header set Access-Control-Allow-Origin "%{KEEPLORE_UI_ORIGIN}e"', $source);
    }

    private function apiOrigin(string $module, ?string $metaContent): string
    {
        $url = json_encode('file://' . $module);
        $meta = json_encode($metaContent);
        $script = <<<JS
const content = {$meta};
globalThis.document = {
  querySelector: (sel) => sel === 'meta[name="keeplore-api-origin"]' && content !== null
    ? { content: content }
    : null,
};
const { API_ORIGIN } = await import({$url});
process.stdout.write(API_ORIGIN);
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

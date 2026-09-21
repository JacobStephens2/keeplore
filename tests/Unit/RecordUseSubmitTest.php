<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: RecordUseSubmit.bind(form, options).
 *
 * Pages that record a use without leaving POST through this helper:
 * preventDefault, X-Requested-With, toast, onSuccess. Callers pass fetch
 * so tests observe the request without a browser.
 */
class RecordUseSubmitTest extends TestCase
{
    public function test_submit_posts_the_form_and_reports_success(): void
    {
        $result = $this->submit(['artifactId' => '2807']);

        $this->assertTrue($result['prevented']);
        $this->assertSame('/uses/record-new.php', $result['fetched']['url']);
        $this->assertSame('POST', $result['fetched']['opts']['method']);
        $this->assertSame('XMLHttpRequest', $result['fetched']['opts']['headers']['X-Requested-With']);
        $this->assertSame('form-data', $result['fetched']['opts']['body']);
        $this->assertSame([['recorded', 'success']], $result['toasts']);
        $this->assertSame(9, $result['successes'][0]['use_id']);
        $this->assertFalse($result['saveBtn']['disabled']);
        $this->assertSame('Record use', $result['saveBtn']['textContent']);
    }

    public function test_missing_item_does_not_post(): void
    {
        $result = $this->submit(['artifactId' => '']);

        $this->assertTrue($result['prevented']);
        $this->assertNull($result['fetched']);
        $this->assertSame([['Please choose an item.', 'error']], $result['toasts']);
        $this->assertSame([], $result['successes']);
    }

    public function test_failed_response_toasts_the_server_message(): void
    {
        $result = $this->submit([
            'artifactId' => '1',
            'response' => ['ok' => false, 'status' => 400, 'body' => ['ok' => false, 'message' => 'Please choose an item.']],
        ]);

        $this->assertSame([], $result['successes']);
        $this->assertSame([['Please choose an item.', 'error']], $result['toasts']);
        $this->assertFalse($result['saveBtn']['disabled']);
        $this->assertSame('Record use', $result['saveBtn']['textContent']);
    }

    public function test_network_error_toasts_and_restores_the_button(): void
    {
        $result = $this->submit([
            'artifactId' => '1',
            'reject' => 'offline',
        ]);

        $this->assertSame([], $result['successes']);
        $this->assertSame([['Network error: offline', 'error']], $result['toasts']);
        $this->assertFalse($result['saveBtn']['disabled']);
        $this->assertSame('Record use', $result['saveBtn']['textContent']);
    }

    public function test_record_pages_bind_the_shared_module(): void
    {
        $pages = [
            PROJECT_PATH . '/ui/artifacts/useby.php',
            PROJECT_PATH . '/ui/index.php',
            PROJECT_PATH . '/private/shared/user_interactions.php',
        ];
        foreach ($pages as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'record-use-submit.js',
                $source,
                $path . ' must load the shared submit module.'
            );
            $this->assertStringContainsString(
                'RecordUseSubmit.bind',
                $source,
                $path . ' must bind the shared submit module.'
            );
        }
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private function submit(array $opts): array
    {
        $module = json_encode(PROJECT_PATH . '/ui/shared/js/record-use-submit.js');
        $optsJson = json_encode($opts);
        $script = <<<JS
const RecordUseSubmit = require({$module});
const opts = {$optsJson};
const listeners = {};
const form = {
  action: '/uses/record-new.php',
  addEventListener: (type, fn) => { listeners[type] = fn; },
};
const saveBtn = { disabled: false, textContent: 'Record use' };
const artifactIdInput = { value: opts.artifactId };
let fetched = null;
const fetchFn = (url, fetchOpts) => {
  fetched = { url, opts: fetchOpts };
  if (opts.reject) {
    return Promise.reject(new Error(opts.reject));
  }
  const response = opts.response || { ok: true, body: { ok: true, message: 'recorded', use_id: 9 } };
  return Promise.resolve({
    ok: response.ok !== false,
    json: () => Promise.resolve(response.body),
  });
};
const toasts = [];
const successes = [];
RecordUseSubmit.bind(form, {
  fetch: fetchFn,
  toast: (m, k) => toasts.push([m, k]),
  saveButton: saveBtn,
  saveLabel: 'Record use',
  artifactIdInput,
  onSuccess: (data) => successes.push(data),
  body: 'form-data',
});
const event = { prevented: false, preventDefault() { event.prevented = true; } };
const pending = listeners.submit(event);
Promise.resolve(pending).then(() => {
  process.stdout.write(JSON.stringify({
    prevented: event.prevented,
    fetched,
    toasts,
    successes,
    saveBtn,
  }));
});
JS;

        $cmd = 'node -e ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        $this->assertSame(0, $code, $raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}

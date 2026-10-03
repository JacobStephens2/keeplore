<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ui/uses/record-new.js (RecordNew).
 *
 * bindEnter: Enter anywhere on Record Use submits the form through
 * requestSubmit, so the browser still checks Number of uses against its
 * 1-20 range instead of form.submit() posting past it.
 *
 * keepValueOnWheel: scrolling over a focused Number of uses scrolls the
 * page instead of changing the number.
 */
class RecordNewTest extends TestCase
{
    public function test_enter_submits_through_validation(): void
    {
        $result = $this->press(['key' => 'Enter']);

        $this->assertSame('requestSubmit', $result['submitted']);
        $this->assertTrue($result['prevented']);
    }

    public function test_enter_falls_back_to_submit_without_request_submit(): void
    {
        $this->assertSame('submit', $this->press(['key' => 'Enter'], false)['submitted']);
    }

    public function test_other_keys_do_not_submit(): void
    {
        $result = $this->press(['key' => 'a']);

        $this->assertNull($result['submitted']);
        $this->assertFalse($result['prevented']);
    }

    public function test_wheel_over_the_focused_number_blurs_it_so_the_page_scrolls(): void
    {
        $this->assertSame(['blurred' => true, 'prevented' => false], $this->wheel(true));
    }

    public function test_wheel_over_the_number_when_not_focused_does_nothing(): void
    {
        $this->assertSame(['blurred' => false, 'prevented' => false], $this->wheel(false));
    }

    public function test_record_new_page_binds_the_enter_handler_instead_of_inline_submit(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-new.php');

        $this->assertStringContainsString('record-new.js', $source);
        $this->assertStringNotContainsString(".submit()", $source);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{submitted: ?string, prevented: bool}
     */
    private function press(array $event, bool $hasRequestSubmit = true): array
    {
        $module = json_encode(PROJECT_PATH . '/ui/uses/record-new.js');
        $eventJson = json_encode($event);
        $hasRequestSubmitJson = json_encode($hasRequestSubmit);
        $script = <<<JS
const RecordNew = require({$module});
const listeners = {};
let submitted = null;
const form = { submit: function () { submitted = 'submit'; } };
if ({$hasRequestSubmitJson}) {
  form.requestSubmit = function () { submitted = 'requestSubmit'; };
}
const doc = { addEventListener: (type, fn) => { listeners[type] = fn; } };
RecordNew.bindEnter(doc, form);
const event = {$eventJson};
event.prevented = false;
event.preventDefault = function () { event.prevented = true; };
if (listeners.keypress) {
  listeners.keypress(event);
}
process.stdout.write(JSON.stringify({ submitted: submitted, prevented: event.prevented }));
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

    /** @return array{blurred: bool, prevented: bool} */
    private function wheel(bool $focused): array
    {
        $module = json_encode(PROJECT_PATH . '/ui/uses/record-new.js');
        $focusedJson = json_encode($focused);
        $script = <<<JS
const RecordNew = require({$module});
const listeners = {};
let blurred = false;
const input = {
  addEventListener: (type, fn) => { listeners[type] = fn; },
  blur() { blurred = true; },
};
const doc = { activeElement: {$focusedJson} ? input : null };
RecordNew.keepValueOnWheel(doc, input);
const event = { prevented: false, preventDefault() { event.prevented = true; } };
listeners.wheel(event);
process.stdout.write(JSON.stringify({ blurred, prevented: event.prevented }));
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

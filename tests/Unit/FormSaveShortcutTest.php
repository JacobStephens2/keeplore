<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: FormSaveShortcut.bind(document, form).
 *
 * Pressing s (no modifiers, not typing in a textual field) submits the
 * form marked data-shortcut="save".
 */
class FormSaveShortcutTest extends TestCase
{
    public function test_pressing_s_submits_the_save_form(): void
    {
        $this->assertSame('requestSubmit', $this->press(['key' => 's'])['submitted']);
    }

    public function test_pressing_s_in_a_text_input_does_not_submit(): void
    {
        $this->assertNull($this->press([
            'key' => 's',
            'target' => ['tagName' => 'INPUT', 'type' => 'text'],
        ])['submitted']);
    }

    public function test_pressing_s_in_a_textarea_does_not_submit(): void
    {
        $this->assertNull($this->press([
            'key' => 's',
            'target' => ['tagName' => 'TEXTAREA'],
        ])['submitted']);
    }

    public function test_pressing_s_in_a_number_input_does_not_submit(): void
    {
        $this->assertNull($this->press([
            'key' => 's',
            'target' => ['tagName' => 'INPUT', 'type' => 'number'],
        ])['submitted']);
    }

    public function test_pressing_s_on_a_checkbox_submits(): void
    {
        $this->assertSame('requestSubmit', $this->press([
            'key' => 's',
            'target' => ['tagName' => 'INPUT', 'type' => 'checkbox'],
        ])['submitted']);
    }

    public function test_pressing_s_in_a_select_does_not_submit(): void
    {
        $this->assertNull($this->press([
            'key' => 's',
            'target' => ['tagName' => 'SELECT'],
        ])['submitted']);
    }

    public function test_pressing_s_in_contenteditable_does_not_submit(): void
    {
        $this->assertNull($this->press([
            'key' => 's',
            'target' => ['tagName' => 'DIV', 'isContentEditable' => true],
        ])['submitted']);
    }

    public function test_modifier_s_does_not_submit(): void
    {
        $this->assertNull($this->press(['key' => 's', 'ctrlKey' => true])['submitted']);
        $this->assertNull($this->press(['key' => 's', 'metaKey' => true])['submitted']);
        $this->assertNull($this->press(['key' => 's', 'altKey' => true])['submitted']);
    }

    public function test_capital_s_submits_the_save_form(): void
    {
        $this->assertSame('requestSubmit', $this->press(['key' => 'S'])['submitted']);
    }

    public function test_shortcut_is_idle_without_a_form(): void
    {
        $this->assertNull($this->press(['key' => 's'], false)['submitted']);
    }

    public function test_create_item_marks_the_form_as_the_s_save_target(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-shortcut="save"/',
            $source,
            'Create Item must mark its form so s can save it.'
        );
        $this->assertStringContainsString('form-save-shortcut.js', $source);
    }

    public function test_edit_item_marks_the_form_as_the_s_save_target(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/edit.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-shortcut="save"/',
            $source,
            'Edit Item must mark its form so s can save it.'
        );
        $this->assertStringContainsString('form-save-shortcut.js', $source);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{submitted: ?string}
     */
    private function press(array $event, bool $hasForm = true): array
    {
        $event += [
            'key' => 's',
            'metaKey' => false,
            'ctrlKey' => false,
            'altKey' => false,
            'target' => ['tagName' => 'BODY'],
        ];
        $event['target'] += ['tagName' => 'BODY'];

        $module = json_encode(PROJECT_PATH . '/ui/shared/js/form-save-shortcut.js');
        $eventJson = json_encode($event);
        $hasFormJson = json_encode($hasForm);
        $script = <<<JS
const FormSaveShortcut = require({$module});
const listeners = {};
let submitted = null;
const form = {$hasFormJson} ? {
  requestSubmit: function () { submitted = 'requestSubmit'; },
  submit: function () { submitted = 'submit'; },
} : null;
const doc = {
  querySelector: (sel) => sel === '[data-shortcut="save"]' ? form : null,
  addEventListener: (type, fn) => { listeners[type] = fn; },
};
FormSaveShortcut.bind(doc, form);
const event = {$eventJson};
event.preventDefault = function () {};
if (listeners.keydown) {
  listeners.keydown(event);
}
process.stdout.write(JSON.stringify({ submitted: submitted }));
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

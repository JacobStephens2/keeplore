<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: ui/uses/record-edit.php markup.
 *
 * Edit Use has no Enter handler of its own, so Enter in a field submits
 * through the form's first submit button. The + person button must not
 * be one, or Enter adds an empty row instead of saving.
 */
class RecordEditTest extends TestCase
{
    public function test_add_person_button_does_not_submit(): void
    {
        $source = (string) file_get_contents(PROJECT_PATH . '/ui/uses/record-edit.php');

        $this->assertMatchesRegularExpression('/<button\b(?=[^>]*id="addUser")(?=[^>]*type="button")[^>]*>/', $source);
    }
}

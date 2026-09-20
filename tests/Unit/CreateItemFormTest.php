<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Create Item should be ready to type a name as soon as the page loads.
 */
class CreateItemFormTest extends TestCase
{
    public function test_name_field_autofocuses_on_create_item(): void
    {
        $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/id="Title"[^>\n]{0,80}autofocus/',
            $source,
            'The Name field (id Title) must autofocus so the cursor is ready on load.'
        );
    }
}

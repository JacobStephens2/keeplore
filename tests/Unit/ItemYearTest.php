<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemYearTest extends TestCase
{
    public function test_create_form_has_a_year_input(): void
    {
        $form = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertStringContainsString('name="Yr"', $form);
        $this->assertStringContainsString('id="Yr"', $form);
        $this->assertMatchesRegularExpression('/<label for="Yr">Year<\/label>/', $form);
    }

    public function test_blank_year_normalizes_to_null(): void
    {
        $this->assertNull(normalize_artifact_year(''));
        $this->assertNull(normalize_artifact_year('  '));
        $this->assertNull(normalize_artifact_year(null));
        $this->assertSame('1999', normalize_artifact_year('1999'));
    }

    public function test_local_schema_year_column_is_double(): void
    {
        $schema = (string) file_get_contents(PROJECT_PATH . '/database/local-schema.sql');
        $this->assertMatchesRegularExpression('/\bYr\s+DOUBLE\b/i', $schema);
    }
}

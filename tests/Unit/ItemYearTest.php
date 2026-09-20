<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ItemYearTest extends TestCase
{
    private function writers(): string
    {
        return (string) file_get_contents(
            PROJECT_PATH . '/private/query_functions/artifact_queries.php'
        );
    }

    public function test_insert_writes_publication_year(): void
    {
        $this->assertMatchesRegularExpression(
            '/INSERT INTO games\s*\([^)]*\bYr\b/s',
            $this->writers()
        );
    }

    public function test_update_writes_publication_year(): void
    {
        $this->assertMatchesRegularExpression(
            '/UPDATE games SET[\s\S]*\bYr\s*=\s*\?/',
            $this->writers()
        );
    }

    public function test_create_form_has_a_year_input(): void
    {
        $form = (string) file_get_contents(PROJECT_PATH . '/ui/artifacts/new.php');
        $this->assertStringContainsString('name="Yr"', $form);
        $this->assertStringContainsString('id="Yr"', $form);
        $this->assertMatchesRegularExpression('/<label for="Yr">Year<\/label>/', $form);
    }

    public function test_blank_year_normalizes_to_null(): void
    {
        require_once PRIVATE_PATH . '/query_functions/artifact_queries.php';
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

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
}

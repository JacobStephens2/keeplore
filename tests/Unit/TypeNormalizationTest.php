<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9, brief 4: the item `type` string is a denormalized cache of
 * the `types` row referenced by `type_id`. These tests pin that
 * contract without a database: the migration backfills stored strings
 * from the canonical name, and both write paths source the stored
 * string from the type lookup (never from user input).
 */
class TypeNormalizationTest extends TestCase
{
    private function migrationSql(): string
    {
        $path = PROJECT_PATH . '/database/migrations/normalize-games-type.sql';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_migration_rewrites_type_from_canonical_name_by_type_id(): void
    {
        $sql = $this->migrationSql();
        $this->assertMatchesRegularExpression(
            '/UPDATE\s+games\s+JOIN\s+types\s+ON\s+types\.id\s*=\s*games\.type_id/i',
            $sql
        );
        $this->assertMatchesRegularExpression(
            '/SET\s+games\.type\s*=\s*types\.objectType/i',
            $sql
        );
    }

    public function test_write_paths_source_type_from_lookup_not_input(): void
    {
        $source = (string) file_get_contents(
            PROJECT_PATH . '/private/query_functions/artifact_queries.php'
        );
        // Both writers resolve the stored string through the lookup seam.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'get_type_name(')
        );
        // The stored `type` column is never assigned raw request input.
        $this->assertDoesNotMatchRegularExpression(
            '/\btype\b\s*=\s*\$_(POST|GET|REQUEST)/',
            $source
        );
    }

    public function test_schema_doc_declares_type_id_authoritative(): void
    {
        $doc = (string) file_get_contents(PROJECT_PATH . '/docs/DATABASE_SCHEMA.md');
        $this->assertStringContainsString('type_id', $doc);
        $this->assertMatchesRegularExpression(
            '/type_id.*authoritative|authoritative.*type_id/i',
            $doc
        );
    }
}

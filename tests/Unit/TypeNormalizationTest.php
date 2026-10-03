<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9, brief 4: the item `type` string is a denormalized cache of
 * the `types` row referenced by `type_id`. These tests pin that
 * contract without a database: the migration backfills stored strings
 * from the canonical name. That the Items module writes the stored string
 * from the owner's type is tested through the module in ItemsTest.
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

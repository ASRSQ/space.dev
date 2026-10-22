<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IndividualMigrationDdlNormalizationTest extends TestCase
{
    public static function tables(): array
    {
        return [
            'documents' => ['document_change_requests', 'pending_document_id', 'document_id', 'uq_dcr_pending_document'],
            'contexts' => ['technical_context_change_requests', 'pending_technical_context_id', 'technical_context_id', 'uq_tccr_pending_context'],
        ];
    }

    private function ddl(string $table, string $column, string $entity, string $index): string
    {
        $inventory = json_decode(file_get_contents(__DIR__.'/../../docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);

        return preg_replace('/^  UNIQUE KEY `'.preg_quote($index, '/').'` .*$/m',
            "  `$column` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `$entity` else NULL end)) VIRTUAL,\n  UNIQUE KEY `$index` (`$column`),", $inventory['ddl'][$table]);
    }

    #[DataProvider('tables')]
    public function test_only_the_reviewed_introducer_difference_is_equivalent(string $table, string $column, string $entity, string $index): void
    {
        $normalize = require __DIR__.'/../../tools/normalize-individual-migration-ddl.php';
        $expected = $this->ddl($table, $column, $entity, $index);
        $plain = str_replace("= _utf8mb4'pending'", "= 'pending'", $expected);
        $mysql57 = str_replace('bigint unsigned', 'bigint(20) unsigned', $plain);
        self::assertSame($normalize($expected), $normalize($plain));
        self::assertSame($normalize($expected), $normalize($mysql57));
        self::assertSame($normalize($expected), $normalize(str_replace('bigint unsigned', 'bigint(20) unsigned', $expected)));
        self::assertSame($normalize($plain), $normalize(implode("\n", $normalize($plain))));
    }

    #[DataProvider('tables')]
    public function test_real_schema_differences_remain_visible(string $table, string $column, string $entity, string $index): void
    {
        $normalize = require __DIR__.'/../../tools/normalize-individual-migration-ddl.php';
        $expected = $this->ddl($table, $column, $entity, $index);
        $plain = str_replace("= _utf8mb4'pending'", "= 'pending'", $expected);
        $mutations = [
            'predicate' => str_replace("= 'pending'", "= 'approved'", $plain),
            'operator' => str_replace("= 'pending'", "<> 'pending'", $plain),
            'result' => str_replace("then `$entity`", 'then `id`', $plain),
            'null branch' => str_replace('else NULL', 'else 0', $plain),
            'storage' => str_replace('VIRTUAL', 'STORED', $plain),
            'type' => str_replace("`$column` bigint unsigned", "`$column` bigint", $plain),
            'index missing' => str_replace("  UNIQUE KEY `$index` (`$column`),\n", '', $plain),
            'index uniqueness' => str_replace("UNIQUE KEY `$index`", "KEY `$index`", $plain),
            'foreign key missing' => preg_replace('/^  CONSTRAINT .*\n/m', '', $plain),
            'foreign key action' => str_replace('ON DELETE CASCADE', 'ON DELETE SET NULL', $plain),
            'column collation' => str_replace("enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_unicode_ci", "enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_bin", $plain),
            'table charset' => str_replace('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci', $plain),
            'explicit collation' => str_replace("= 'pending'", "= _utf8mb4'pending' COLLATE utf8mb4_bin", $plain),
            'binary' => str_replace("= 'pending'", "= BINARY 'pending'", $plain),
            'other introducer' => str_replace("= 'pending'", "= _binary'pending'", $plain),
            'other literal' => str_replace("DEFAULT 'pending'", "DEFAULT _utf8mb4'pending'", $plain),
        ];
        foreach ($mutations as $label => $actual) {
            self::assertNotSame($normalize($expected), $normalize($actual), $label);
        }
    }

    #[DataProvider('tables')]
    public function test_introducers_outside_the_proven_context_are_preserved(string $table, string $column, string $entity, string $index): void
    {
        $normalize = require __DIR__.'/../../tools/normalize-individual-migration-ddl.php';
        $expected = $this->ddl($table, $column, $entity, $index);
        foreach ([
            str_replace("CREATE TABLE `$table`", 'CREATE TABLE `other_table`', $expected),
            str_replace('utf8mb4_unicode_ci', 'utf8mb4_bin', $expected),
            str_replace("enum('pending','approved','rejected','cancelled')", 'varchar(20)', $expected),
            str_replace("`$column` bigint unsigned", '`other_column` bigint unsigned', $expected),
            str_replace("then `$entity`", 'then `id`', $expected),
        ] as $ddl) {
            self::assertNotSame($normalize($ddl), $normalize(str_replace("= _utf8mb4'pending'", "= 'pending'", $ddl)));
        }
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ProjectDocumentationMigrationSafety
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'documents' => ['section_key'],
        'technical_contexts' => ['section_key'],
    ];

    /** @var array<string, list<string>> */
    private const LEGACY_COLUMNS = [
        'document_change_requests' => ['pending_document_id'],
        'technical_context_change_requests' => ['pending_technical_context_id'],
    ];

    /** @var list<string> */
    private const DRAFT_TABLES = ['document_drafts', 'technical_context_drafts'];

    /** @var array<string, list<string>> */
    private const INDEXES = [
        'document_change_requests' => ['uq_dcr_pending_document'],
        'technical_context_change_requests' => ['uq_tccr_pending_context'],
        'documents' => ['uq_documents_project_section'],
        'technical_contexts' => ['uq_tech_ctx_project_section'],
    ];

    public static function assertNoPartialInstallation(): void
    {
        $present = [];

        foreach (self::LEGACY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    $present[] = "legacy column {$table}.{$column}";
                }
            }
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    $present[] = "{$table}.{$column}";
                }
            }
        }

        foreach (self::DRAFT_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $present[] = "table {$table}";
            }
        }

        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $index) {
                if (Schema::hasIndex($table, $index)) {
                    $present[] = "index {$index}";
                }
            }
        }

        if ($present !== []) {
            throw new RuntimeException(
                'Documentation migration found existing migration-owned schema objects ('
                .implode(', ', $present)
                .'). No schema changes were made. Inspect the schema and migration history, then follow the documented recovery procedure.'
            );
        }
    }

    public static function assertCompleteInstallationBeforeRollback(): void
    {
        $missing = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        foreach (self::DRAFT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = "table {$table}";
            }
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index) {
                if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
                    $missing[] = "index {$index}";
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Documentation migration rollback refused because the installation is partial or inconsistent ('
                .implode(', ', $missing)
                .'). No schema changes were made. Inspect the schema and follow the documented recovery procedure.'
            );
        }
    }

    public static function assertNoFeatureDataBeforeRollback(): void
    {
        $data = [];

        foreach (self::DRAFT_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                $data[] = "rows in {$table}";
            }
        }

        foreach (['documents', 'technical_contexts'] as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'section_key')
                && DB::table($table)->whereNotNull('section_key')->exists()) {
                $data[] = "section assignments in {$table}";
            }
        }

        if ($data !== []) {
            throw new RuntimeException(
                'Documentation migration rollback refused because feature data would be lost ('
                .implode(', ', $data)
                .'). Export and verify this data before following the documented rollback procedure. No schema changes were made.'
            );
        }
    }
}

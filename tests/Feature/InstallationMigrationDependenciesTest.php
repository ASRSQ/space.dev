<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class InstallationMigrationDependenciesTest extends TestCase
{
    public function test_documentation_reports_missing_baseline_before_querying_domain_tables(): void
    {
        $migration = require database_path('migrations/2026_10_18_000003_add_project_documentation_workflow.php');
        try {
            $migration->up();
            $this->fail('Missing baseline was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('domain baseline first', $error->getMessage());
            $this->assertStringContainsString('document_change_requests', $error->getMessage());
        }
        $this->assertFalse(Schema::hasTable('document_drafts'));
    }

    public function test_deadlines_report_missing_tasks_before_altering_schema(): void
    {
        $migration = require database_path('migrations/2026_10_19_000001_add_task_deadlines.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tasks is missing');
        $migration->up();
    }
}

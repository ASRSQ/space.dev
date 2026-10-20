<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DocumentationMySqlValidationTarget;
use Tests\TestCase;

class MySqlProjectDocumentationMigrationPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DOCUMENTATION_MYSQL_VALIDATION') !== '1') {
            $this->markTestSkipped('Only run explicitly against the isolated MySQL migration-validation database.');
        }

        config(['database.default' => 'mysql']);
        DocumentationMySqlValidationTarget::assertConnected();

        if (Schema::connection('mysql')->hasTable('document_drafts')
            || Schema::connection('mysql')->hasColumn('documents', 'section_key')
            || Schema::connection('mysql')->hasIndex('document_change_requests', 'uq_dcr_pending_document')) {
            $this->markTestSkipped('Preflight tests require the controlled pre-migration fixture state.');
        }
    }

    public function test_existing_duplicate_pending_requests_abort_before_any_schema_change(): void
    {
        DB::connection('mysql')->beginTransaction();

        try {
            [$userId, $projectId] = $this->insertOwnerAndProject();
            $documentId = DB::connection('mysql')->table('documents')->insertGetId([
                'project_id' => $projectId,
                'title' => 'Duplicate-check document',
                'slug' => 'duplicate-check-document',
            ]);
            $contextId = DB::connection('mysql')->table('technical_contexts')->insertGetId([
                'project_id' => $projectId,
                'title' => 'Duplicate-check context',
                'slug' => 'duplicate-check-context',
            ]);

            foreach ([1, 2] as $version) {
                DB::connection('mysql')->table('document_change_requests')->insert([
                    'document_id' => $documentId,
                    'proposed_version_number' => $version,
                    'proposed_title' => 'Pending '.$version,
                    'proposed_content' => 'Content '.$version,
                    'reason' => 'Preflight duplicate fixture',
                    'status' => 'pending',
                    'author_user_id' => $userId,
                ]);
                DB::connection('mysql')->table('technical_context_change_requests')->insert([
                    'technical_context_id' => $contextId,
                    'proposed_version_number' => $version,
                    'proposed_architecture_summary' => 'Context '.$version,
                    'reason' => 'Preflight duplicate fixture',
                    'status' => 'pending',
                    'author_user_id' => $userId,
                ]);
            }

            try {
                $this->migration()->up();
                $this->fail('Migration accepted pre-existing duplicate pending requests.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('duplicate pending change requests', $exception->getMessage());
            }

            $this->assertFalse(Schema::connection('mysql')->hasColumn('documents', 'section_key'));
            $this->assertFalse(Schema::connection('mysql')->hasColumn('technical_contexts', 'section_key'));
            $this->assertFalse(Schema::connection('mysql')->hasIndex('document_change_requests', 'uq_dcr_pending_document'));
            $this->assertFalse(Schema::connection('mysql')->hasIndex('technical_context_change_requests', 'uq_tccr_pending_context'));
            $this->assertFalse(Schema::connection('mysql')->hasTable('document_drafts'));
        } finally {
            if (DB::connection('mysql')->transactionLevel() > 0) {
                DB::connection('mysql')->rollBack();
            }
        }
    }

    public function test_partial_functional_index_state_blocks_retry_without_touching_other_objects(): void
    {
        DB::connection('mysql')->statement("CREATE UNIQUE INDEX uq_dcr_pending_document ON document_change_requests ((CASE WHEN status = 'pending' THEN document_id ELSE NULL END))");

        try {
            try {
                $this->migration()->up();
                $this->fail('Migration retry proceeded with a partial migration-owned index present.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('existing migration-owned schema objects', $exception->getMessage());
            }

            $this->assertTrue(Schema::connection('mysql')->hasIndex('document_change_requests', 'uq_dcr_pending_document'));
            $this->assertFalse(Schema::connection('mysql')->hasColumn('documents', 'section_key'));
            $this->assertFalse(Schema::connection('mysql')->hasTable('document_drafts'));
        } finally {
            DB::connection('mysql')->statement('DROP INDEX uq_dcr_pending_document ON document_change_requests');
        }

        $this->assertFalse(Schema::connection('mysql')->hasIndex('document_change_requests', 'uq_dcr_pending_document'));
    }

    /** @return array{int, int} */
    private function insertOwnerAndProject(): array
    {
        $roleId = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'Preflight administrator',
            'slug' => 'preflight-administrator',
        ]);
        $userId = DB::connection('mysql')->table('users')->insertGetId([
            'role_id' => $roleId,
            'name' => 'Preflight user',
            'email' => 'preflight-'.bin2hex(random_bytes(8)).'@example.test',
            'password_hash' => 'temporary-test-hash',
            'status' => 'active',
        ]);
        $projectId = DB::connection('mysql')->table('projects')->insertGetId([
            'name' => 'Preflight project',
            'slug' => 'preflight-'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'owner_user_id' => $userId,
        ]);

        return [$userId, $projectId];
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_000003_add_project_documentation_workflow.php');
    }
}

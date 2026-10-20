<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChangeRequestDecision;
use App\Models\DocumentDraft;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Role;
use App\Models\TechnicalContext;
use App\Models\TechnicalContextChangeRequestDecision;
use App\Models\TechnicalContextVersion;
use App\Models\User;
use App\Services\ProjectDocumentationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DocumentationMySqlValidationTarget;
use Tests\TestCase;

class MySqlProjectDocumentationValidationTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DOCUMENTATION_MYSQL_VALIDATION') !== '1') {
            $this->markTestSkipped('Only run explicitly against the isolated MySQL validation database.');
        }

        config(['database.default' => 'mysql']);
        DocumentationMySqlValidationTarget::assertConnected();
        DB::connection('mysql')->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        parent::tearDown();
    }

    public function test_mysql_target_and_migration_objects_match_the_isolated_instance(): void
    {
        $target = DocumentationMySqlValidationTarget::assertConnected();

        $this->assertStringStartsWith('8.4.3', (string) $target['server_version']);
        $this->assertSame('127.0.0.1', $target['bind_address']);
        $this->assertSame(13317, (int) $target['server_port']);
        $this->assertSame('spacedev_documentation_migration_validation', $target['database_name']);

        foreach ([
            ['documents', 'section_key'],
            ['technical_contexts', 'section_key'],
        ] as [$table, $column]) {
            $this->assertTrue(Schema::connection('mysql')->hasColumn($table, $column));
        }

        $this->assertTrue(Schema::connection('mysql')->hasTable('document_drafts'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('technical_context_drafts'));

        $foreignKeys = DB::connection('mysql')->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DocumentationMySqlValidationTarget::DATABASE)
            ->whereIn('CONSTRAINT_NAME', ['fk_document_drafts_document', 'fk_document_drafts_user', 'fk_technical_context_drafts_context', 'fk_technical_context_drafts_user'])
            ->count();
        $this->assertSame(4, $foreignKeys);

        foreach ([
            ['document_change_requests', 'uq_dcr_pending_document'],
            ['technical_context_change_requests', 'uq_tccr_pending_context'],
        ] as [$table, $index]) {
            $metadata = DB::connection('mysql')->table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DocumentationMySqlValidationTarget::DATABASE)
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)
                ->select('NON_UNIQUE', 'EXPRESSION')
                ->first();
            $this->assertNotNull($metadata);
            $this->assertSame(0, (int) $metadata->NON_UNIQUE);
            $this->assertStringContainsString('CASE', strtoupper((string) $metadata->EXPRESSION));
            $this->assertStringContainsString('PENDING', strtoupper((string) $metadata->EXPRESSION));
        }

        $this->assertFalse(Schema::connection('mysql')->hasColumn('document_change_requests', 'pending_document_id'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('technical_context_change_requests', 'pending_technical_context_id'));
    }

    public function test_mysql_publication_preserves_draft_review_history_and_version_integrity(): void
    {
        [$admin, $project] = $this->makeProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);

        $service->saveDraft($project, 'overview', ['content_markdown' => '# MySQL published'], $admin);
        $document = Document::query()->where('project_id', $project->getKey())->where('section_key', 'overview')->sole();
        $this->assertNull($document->current_version_id);

        $request = $service->submitForReview($project, 'overview', 'Isolated MySQL publication', $admin);
        $this->assertSame('pending', $request->status);
        $this->assertNull($document->fresh()->current_version_id);

        $version = $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Approved in validation', $admin);

        $this->assertInstanceOf(DocumentVersion::class, $version);
        $this->assertSame($version->getKey(), $document->fresh()->current_version_id);
        $this->assertSame($document->getKey(), $version->fresh()->document_id);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, DocumentChangeRequestDecision::query()->where('change_request_id', $request->getKey())->count());
        $this->assertNull($document->fresh()->draft);

        $service->saveDraft($project, 'architecture', ['architecture_summary' => '# MySQL architecture'], $admin);
        $context = TechnicalContext::query()->where('project_id', $project->getKey())->where('section_key', 'architecture')->sole();
        $contextRequest = $service->submitForReview($project, 'architecture', 'Context validation', $admin);
        $contextVersion = $service->decideTechnicalContext($project, 'architecture', $contextRequest->getKey(), 'approved', 'Approved context', $admin);

        $this->assertInstanceOf(TechnicalContextVersion::class, $contextVersion);
        $this->assertSame($context->getKey(), $contextVersion->fresh()->technical_context_id);
        $this->assertSame($contextVersion->getKey(), $context->fresh()->current_version_id);
        $this->assertSame(1, TechnicalContextChangeRequestDecision::query()->where('change_request_id', $contextRequest->getKey())->count());
    }

    public function test_mysql_functional_unique_indexes_reject_duplicate_pending_document_and_context_requests(): void
    {
        [$admin, $project] = $this->makeProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Document'], $admin);
        $documentRequest = $service->submitForReview($project, 'overview', 'Initial review', $admin);
        try {
            $documentRequest->newQuery()->create([
                'document_id' => $documentRequest->document_id,
                'proposed_version_number' => 2,
                'proposed_title' => 'Duplicate pending',
                'proposed_content' => 'Duplicate',
                'reason' => 'Constraint validation',
                'status' => 'pending',
                'author_user_id' => $admin->getKey(),
            ]);
            $this->fail('Duplicate pending document request was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::connection('mysql')->table('document_change_requests')->where('status', 'pending')->count());
        }
        $documentRequest->newQuery()->whereKey($documentRequest->getKey())->update(['status' => 'rejected']);
        $documentRequest->newQuery()->create([
            'document_id' => $documentRequest->document_id,
            'proposed_version_number' => 2,
            'proposed_title' => 'Resolved historical request',
            'proposed_content' => 'Historical content',
            'reason' => 'Resolved request must not occupy the pending key.',
            'status' => 'approved',
            'author_user_id' => $admin->getKey(),
        ]);
        $documentRequest->newQuery()->create([
            'document_id' => $documentRequest->document_id,
            'proposed_version_number' => 3,
            'proposed_title' => 'Next pending request',
            'proposed_content' => 'Next proposal',
            'reason' => 'A new pending request is allowed after resolution.',
            'status' => 'pending',
            'author_user_id' => $admin->getKey(),
        ]);

        $service->saveDraft($project, 'architecture', ['architecture_summary' => '# Architecture'], $admin);
        $request = $service->submitForReview($project, 'architecture', 'Initial review', $admin);

        try {
            $request->newQuery()->create([
                'technical_context_id' => $request->technical_context_id,
                'proposed_version_number' => 2,
                'proposed_architecture_summary' => 'Duplicate pending',
                'reason' => 'Constraint validation',
                'status' => 'pending',
                'author_user_id' => $admin->getKey(),
            ]);
            $this->fail('Duplicate pending context request was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::connection('mysql')->table('technical_context_change_requests')->where('status', 'pending')->count());
        }
        $request->newQuery()->whereKey($request->getKey())->update(['status' => 'cancelled']);
        $request->newQuery()->create([
            'technical_context_id' => $request->technical_context_id,
            'proposed_version_number' => 2,
            'proposed_architecture_summary' => 'Resolved context request',
            'reason' => 'Resolved request must not occupy the pending key.',
            'status' => 'rejected',
            'author_user_id' => $admin->getKey(),
        ]);
        $request->newQuery()->create([
            'technical_context_id' => $request->technical_context_id,
            'proposed_version_number' => 3,
            'proposed_architecture_summary' => 'Next pending context request',
            'reason' => 'A new pending request is allowed after cancellation.',
            'status' => 'pending',
            'author_user_id' => $admin->getKey(),
        ]);
    }

    public function test_mysql_draft_foreign_keys_reject_orphan_document_and_context_rows(): void
    {
        [$admin] = $this->makeProject();

        foreach ([
            ['document_drafts', 'document_id', ['content_markdown' => 'orphan']],
            ['technical_context_drafts', 'technical_context_id', ['architecture_summary' => 'orphan']],
        ] as [$table, $entityColumn, $content]) {
            try {
                DB::connection('mysql')->table($table)->insert([
                    $entityColumn => PHP_INT_MAX,
                    'updated_by_user_id' => $admin->getKey(),
                    ...$content,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail("{$table} accepted a draft without its parent entity.");
            } catch (QueryException) {
                $this->assertSame(0, DB::connection('mysql')->table($table)->count());
            }
        }
    }

    public function test_mysql_failure_after_request_update_rolls_back_publication_and_retains_draft(): void
    {
        [$admin, $project] = $this->makeProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Keep draft'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Rollback validation', $admin);

        Event::listen('eloquent.created: '.DocumentChangeRequestDecision::class, function (): void {
            throw new RuntimeException('Controlled final publication failure.');
        });

        try {
            $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Approve', $admin);
            $this->fail('The controlled final-stage failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled final publication failure.', $exception->getMessage());
        }

        $document = Document::query()->where('project_id', $project->getKey())->where('section_key', 'overview')->sole();
        $this->assertNull($document->fresh()->current_version_id);
        $this->assertInstanceOf(DocumentDraft::class, $document->fresh()->draft);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, DocumentVersion::query()->where('document_id', $document->getKey())->count());
        $this->assertSame(0, DocumentChangeRequestDecision::query()->where('change_request_id', $request->getKey())->count());
    }

    public function test_mysql_rollback_refuses_to_drop_identified_section_data_or_drafts(): void
    {
        [$admin, $project] = $this->makeProject();
        $this->actingAs($admin, 'web');
        app(ProjectDocumentationService::class)->saveDraft($project, 'overview', ['content_markdown' => '# Preserve'], $admin);

        $migration = require base_path('database/migrations/2026_10_18_000003_add_project_documentation_workflow.php');

        try {
            $migration->down();
            $this->fail('Rollback was allowed despite documentation feature data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('feature data would be lost', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection('mysql')->hasTable('document_drafts'));
        $this->assertTrue(Schema::connection('mysql')->hasColumn('documents', 'section_key'));
        $this->assertSame(1, DB::connection('mysql')->table('document_drafts')->count());
    }

    /** @return array{User, Project} */
    private function makeProject(): array
    {
        $role = Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
        $admin = User::query()->create([
            'role_id' => $role->getKey(),
            'name' => 'Temporary MySQL validation administrator',
            'email' => 'mysql-validation-'.bin2hex(random_bytes(8)).'@example.test',
            'password_hash' => 'non-shared-test-credential',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'name' => 'Temporary documentation validation project',
            'slug' => 'mysql-validation-'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'owner_user_id' => $admin->getKey(),
        ]);

        return [$admin, $project];
    }
}

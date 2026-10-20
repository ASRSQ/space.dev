<?php

namespace Tests\Feature;

use App\Support\ProjectDocumentationMigrationSafety;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ProjectDocumentationMigrationSafetyTest extends TestCase
{
    public function test_partial_migration_schema_is_detected_before_any_retry(): void
    {
        Schema::create('documents', function ($table): void {
            $table->id();
            $table->string('section_key', 64)->nullable();
        });

        try {
            ProjectDocumentationMigrationSafety::assertNoPartialInstallation();
            $this->fail('A partially applied migration was not detected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing migration-owned schema objects', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('documents', 'section_key'));
            $this->assertFalse(Schema::hasTable('document_drafts'));
            $this->assertFalse(Schema::hasIndex('document_change_requests', 'uq_dcr_pending_document'));
        }
    }

    public function test_legacy_pending_document_column_is_detected_as_unexpected_schema(): void
    {
        Schema::create('document_change_requests', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('pending_document_id')->nullable();
        });

        try {
            ProjectDocumentationMigrationSafety::assertNoPartialInstallation();
            $this->fail('A legacy pending-document column was not detected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('legacy column document_change_requests.pending_document_id', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('document_change_requests', 'pending_document_id'));
        }
    }

    public function test_legacy_pending_technical_context_column_is_detected_as_unexpected_schema(): void
    {
        Schema::create('technical_context_change_requests', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('pending_technical_context_id')->nullable();
        });

        try {
            ProjectDocumentationMigrationSafety::assertNoPartialInstallation();
            $this->fail('A legacy pending-context column was not detected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('legacy column technical_context_change_requests.pending_technical_context_id', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('technical_context_change_requests', 'pending_technical_context_id'));
        }
    }

    public function test_absent_legacy_columns_allow_clean_check_and_functional_index_names_are_still_detected(): void
    {
        Schema::create('document_change_requests', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('status');
        });
        Schema::create('technical_context_change_requests', function ($table): void {
            $table->id();
        });

        ProjectDocumentationMigrationSafety::assertNoPartialInstallation();

        Schema::getConnection()->statement("CREATE UNIQUE INDEX uq_dcr_pending_document ON document_change_requests (CASE WHEN status = 'pending' THEN document_id ELSE NULL END)");

        try {
            ProjectDocumentationMigrationSafety::assertNoPartialInstallation();
            $this->fail('An existing migration-owned index name was not detected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('index uq_dcr_pending_document', $exception->getMessage());
        }
    }

    public function test_rollback_guard_preserves_draft_rows_and_section_assignments(): void
    {
        Schema::create('documents', function ($table): void {
            $table->id();
            $table->string('section_key', 64)->nullable();
        });
        Schema::create('technical_contexts', function ($table): void {
            $table->id();
            $table->string('section_key', 64)->nullable();
        });
        Schema::create('document_drafts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->text('content_markdown');
        });
        Schema::create('technical_context_drafts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('technical_context_id');
            $table->text('architecture_summary');
        });

        $documentId = Schema::getConnection()->table('documents')->insertGetId(['section_key' => 'overview']);
        Schema::getConnection()->table('technical_contexts')->insert(['section_key' => 'architecture']);
        Schema::getConnection()->table('document_drafts')->insert([
            'document_id' => $documentId,
            'content_markdown' => '# Recoverable draft',
        ]);
        Schema::getConnection()->table('technical_context_drafts')->insert([
            'technical_context_id' => 1,
            'architecture_summary' => '# Recoverable context draft',
        ]);

        try {
            ProjectDocumentationMigrationSafety::assertNoFeatureDataBeforeRollback();
            $this->fail('Rollback was allowed while documentation data existed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('feature data would be lost', $exception->getMessage());
            $this->assertSame('# Recoverable draft', Schema::getConnection()->table('document_drafts')->value('content_markdown'));
            $this->assertSame('# Recoverable context draft', Schema::getConnection()->table('technical_context_drafts')->value('architecture_summary'));
            $this->assertSame('overview', Schema::getConnection()->table('documents')->value('section_key'));
            $this->assertSame('architecture', Schema::getConnection()->table('technical_contexts')->value('section_key'));
        }
    }
}

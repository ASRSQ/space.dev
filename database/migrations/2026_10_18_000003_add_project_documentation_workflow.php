<?php

use App\Support\DomainMigrationPlatform;
use App\Support\ProjectDocumentationMigrationSafety;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ProjectDocumentationMigrationSafety::assertNoPartialInstallation();

        $requiredTables = ['users', 'documents', 'document_versions', 'document_change_requests', 'document_change_request_decisions', 'technical_contexts', 'technical_context_versions', 'technical_context_change_requests', 'technical_context_change_request_decisions'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            throw new RuntimeException('Documentation migration requires the domain baseline first. Missing tables: '.implode(', ', $missingTables).'. Upload the complete individual migration chain. No schema changes were made.');
        }

        $duplicateDocumentReviews = DB::table('document_change_requests')
            ->select('document_id')
            ->where('status', 'pending')
            ->groupBy('document_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $duplicateContextReviews = DB::table('technical_context_change_requests')
            ->select('technical_context_id')
            ->where('status', 'pending')
            ->groupBy('technical_context_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateDocumentReviews || $duplicateContextReviews) {
            throw new RuntimeException('Documentation migration aborted: duplicate pending change requests must be reviewed before adding the concurrency constraints.');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Documentation migration requires MySQL generated columns.');
        }

        DomainMigrationPlatform::assertSupported();
        Schema::table('document_change_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('pending_document_id')->nullable()
                ->virtualAs("CASE WHEN status = 'pending' THEN document_id ELSE NULL END");
            $table->unique('pending_document_id', 'uq_dcr_pending_document');
        });
        Schema::table('technical_context_change_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('pending_technical_context_id')->nullable()
                ->virtualAs("CASE WHEN status = 'pending' THEN technical_context_id ELSE NULL END");
            $table->unique('pending_technical_context_id', 'uq_tccr_pending_context');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('section_key', 64)->nullable()->after('slug');
            $table->unique(['project_id', 'section_key'], 'uq_documents_project_section');
        });

        Schema::table('technical_contexts', function (Blueprint $table): void {
            $table->string('section_key', 64)->nullable()->after('slug');
            $table->unique(['project_id', 'section_key'], 'uq_tech_ctx_project_section');
        });

        Schema::create('document_drafts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('document_id')->unique('uq_document_drafts_document');
            $table->mediumText('content_markdown');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign('document_id', 'fk_document_drafts_document')
                ->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('updated_by_user_id', 'fk_document_drafts_user')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('technical_context_drafts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('technical_context_id')->unique('uq_technical_context_drafts_context');
            $table->mediumText('architecture_summary');
            $table->json('tech_stack_json')->nullable();
            $table->mediumText('conventions_markdown')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign('technical_context_id', 'fk_technical_context_drafts_context')
                ->references('id')->on('technical_contexts')->cascadeOnDelete();
            $table->foreign('updated_by_user_id', 'fk_technical_context_drafts_user')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        ProjectDocumentationMigrationSafety::assertCompleteInstallationBeforeRollback();
        ProjectDocumentationMigrationSafety::assertNoFeatureDataBeforeRollback();

        Schema::dropIfExists('technical_context_drafts');
        Schema::dropIfExists('document_drafts');

        DB::statement('DROP INDEX uq_tccr_pending_context ON technical_context_change_requests');
        DB::statement('DROP INDEX uq_dcr_pending_document ON document_change_requests');
        // The original MySQL 8 installation had functional indexes and no
        // explicit pending columns. Preserve rollback support for that shape.
        if (Schema::hasColumn('document_change_requests', 'pending_document_id')) {
            Schema::table('document_change_requests', fn (Blueprint $table) => $table->dropColumn('pending_document_id'));
        }
        if (Schema::hasColumn('technical_context_change_requests', 'pending_technical_context_id')) {
            Schema::table('technical_context_change_requests', fn (Blueprint $table) => $table->dropColumn('pending_technical_context_id'));
        }

        Schema::table('technical_contexts', function (Blueprint $table): void {
            $table->dropUnique('uq_tech_ctx_project_section');
            $table->dropColumn('section_key');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('uq_documents_project_section');
            $table->dropColumn('section_key');
        });
    }
};

<?php

use App\Support\DomainMigrationPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DomainMigrationPlatform::assertSupported();
        Schema::create('document_change_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('document_id')->unsigned()->comment('Documento afetado');
            $table->integer('proposed_version_number')->unsigned()->comment('Número da nova versão proposta');
            $table->string('proposed_title', 200)->collation('utf8mb4_unicode_ci')->comment('Título proposto');
            $table->mediumText('proposed_content')->collation('utf8mb4_unicode_ci')->comment('Conteúdo Markdown proposto');
            $table->text('reason')->collation('utf8mb4_unicode_ci')->comment('Justificativa da alteração');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->collation('utf8mb4_unicode_ci')->default('pending')->comment('Estado da solicitação');
            $table->bigInteger('author_user_id')->unsigned()->comment('Autor da proposta');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['document_id', 'status'], 'idx_dcr_document_status');
            $table->index(['author_user_id'], 'idx_dcr_author');
            $table->comment('Solicitações de alteração em documentos');
        });
        DB::statement('ALTER TABLE `document_change_requests` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

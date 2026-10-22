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
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('document_id')->unsigned()->comment('Documento pai da versão');
            $table->integer('version_number')->unsigned()->comment('Número sequencial da versão (1, 2, 3...)');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título registrado nesta versão');
            $table->mediumText('content_markdown')->collation('utf8mb4_unicode_ci')->comment('Conteúdo completo do documento em Markdown');
            $table->text('changelog')->nullable()->collation('utf8mb4_unicode_ci')->comment('Resumo das alterações em relação à versão anterior');
            $table->bigInteger('created_by_user_id')->unsigned()->comment('Autor da versão');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number'], 'uq_doc_versions_number');
            $table->index(['created_by_user_id'], 'idx_doc_versions_author');
            $table->comment('Histórico imutável de versões de documentos');
        });
        DB::statement('ALTER TABLE `document_versions` ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE `document_versions` ADD UNIQUE KEY `uq_doc_versions_pair` (`id`,`document_id`) COMMENT \'Necessário para FK composta de integridade de versão em documents\'');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

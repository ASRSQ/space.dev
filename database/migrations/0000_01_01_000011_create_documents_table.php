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
        Schema::create('documents', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto ao qual o documento pertence');
            $table->bigInteger('category_id')->unsigned()->nullable()->comment('Categoria do documento');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título do documento');
            $table->string('slug', 200)->collation('utf8mb4_unicode_ci')->comment('Slug do documento no projeto');
            $table->bigInteger('current_version_id')->unsigned()->nullable()->comment('Versão oficial atual do documento');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento');
            $table->dateTime('archived_at')->nullable()->comment('Momento do arquivamento');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['project_id', 'slug'], 'uq_documents_project_slug');
            $table->index(['category_id'], 'idx_documents_category');
            $table->index(['current_version_id'], 'idx_documents_current_version');
            $table->index(['is_archived'], 'idx_documents_archived');
            $table->index(['current_version_id', 'id'], 'fk_documents_current_version_pair');
            $table->comment('Documentos centrais do projeto');
        });
        DB::statement('ALTER TABLE `documents` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

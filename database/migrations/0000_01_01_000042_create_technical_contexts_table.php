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
        Schema::create('technical_contexts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto detentor do contexto técnico');
            $table->string('title', 150)->collation('utf8mb4_unicode_ci')->comment('Título do contexto técnico');
            $table->string('slug', 150)->collation('utf8mb4_unicode_ci')->comment('Slug do contexto');
            $table->text('description')->nullable()->collation('utf8mb4_unicode_ci')->comment('Finalidade do contexto arquitetural');
            $table->bigInteger('current_version_id')->unsigned()->nullable()->comment('Versão oficial ativa');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento');
            $table->dateTime('archived_at')->nullable()->comment('Data do arquivamento');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['project_id', 'slug'], 'uq_tech_ctx_proj_slug');
            $table->index(['current_version_id'], 'idx_tech_ctx_current_ver');
            $table->index(['current_version_id', 'id'], 'fk_tech_ctx_current_version_pair');
            $table->comment('Contextos de arquitetura e tecnologia de projetos');
        });
        DB::statement('ALTER TABLE `technical_contexts` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

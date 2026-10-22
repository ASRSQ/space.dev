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
        Schema::create('prompt_templates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->nullable()->comment('Projeto ao qual pertence (NULL indica prompt global)');
            $table->bigInteger('category_id')->unsigned()->nullable()->comment('Categoria de prompt (tabela compartilhada categories)');
            $table->string('name', 150)->collation('utf8mb4_unicode_ci')->comment('Nome de exibição do prompt');
            $table->string('slug', 150)->collation('utf8mb4_unicode_ci')->comment('Slug identificador');
            $table->text('description')->nullable()->collation('utf8mb4_unicode_ci')->comment('Finalidade de uso do prompt');
            $table->bigInteger('current_version_id')->unsigned()->nullable()->comment('Versão oficial ativa');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento');
            $table->dateTime('archived_at')->nullable()->comment('Momento do arquivamento');
            $table->bigInteger('created_by_user_id')->unsigned()->comment('Criador inicial do template');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['project_id', 'slug'], 'uq_prompt_proj_slug');
            $table->index(['category_id'], 'idx_prompts_category');
            $table->index(['current_version_id'], 'idx_prompts_current_ver');
            $table->index(['created_by_user_id'], 'idx_prompts_author');
            $table->index(['current_version_id', 'id'], 'fk_prompts_current_version_pair');
            $table->comment('Biblioteca central de prompts');
        });
        DB::statement('ALTER TABLE `prompt_templates` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

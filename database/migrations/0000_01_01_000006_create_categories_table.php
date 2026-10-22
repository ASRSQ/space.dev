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
        Schema::create('categories', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->nullable()->comment('Projeto ao qual pertence (NULL indica categoria global)');
            $table->string('name', 100)->collation('utf8mb4_unicode_ci')->comment('Nome da categoria');
            $table->string('slug', 100)->collation('utf8mb4_unicode_ci')->comment('Slug identificador');
            $table->string('description', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('Descrição sucinta da finalidade');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['project_id', 'slug'], 'uq_categories_project_slug');
            $table->index(['project_id'], 'idx_categories_project');
            $table->comment('Categorias para organização de documentos e prompts');
        });
        DB::statement('ALTER TABLE `categories` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

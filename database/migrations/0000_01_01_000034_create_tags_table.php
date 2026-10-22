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
        Schema::create('tags', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->nullable()->comment('Projeto ao qual pertence (NULL indica tag global)');
            $table->string('name', 50)->collation('utf8mb4_unicode_ci')->comment('Nome da tag');
            $table->string('slug', 50)->collation('utf8mb4_unicode_ci')->comment('Slug identificador da tag');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['project_id', 'slug'], 'uq_tags_project_slug');
            $table->index(['project_id'], 'idx_tags_project');
            $table->comment('Etiquetas (tags) para categorização transversal');
        });
        DB::statement('ALTER TABLE `tags` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

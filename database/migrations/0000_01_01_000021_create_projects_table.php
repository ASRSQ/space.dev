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
        Schema::create('projects', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->string('name', 150)->collation('utf8mb4_unicode_ci')->comment('Nome do projeto');
            $table->string('slug', 150)->collation('utf8mb4_unicode_ci')->comment('Slug único do projeto na URL');
            $table->text('description')->nullable()->collation('utf8mb4_unicode_ci')->comment('Visão geral e objetivos do projeto');
            $table->string('repository_url', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('URL do repositório remoto Git');
            $table->enum('status', ['active', 'archived', 'paused'])->collation('utf8mb4_unicode_ci')->default('active')->comment('Status do ciclo de vida do projeto');
            $table->bigInteger('owner_user_id')->unsigned()->comment('Usuário proprietário/criador do projeto');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento lógico');
            $table->dateTime('archived_at')->nullable()->comment('Momento do arquivamento');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['slug'], 'uq_projects_slug');
            $table->index(['owner_user_id'], 'idx_projects_owner');
            $table->index(['status', 'is_archived'], 'idx_projects_status');
            $table->comment('Projetos gerenciados na plataforma');
        });
        DB::statement('ALTER TABLE `projects` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

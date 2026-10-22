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
        Schema::create('tasks', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto onde a tarefa está alocada');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título claro da tarefa');
            $table->mediumText('description')->nullable()->collation('utf8mb4_unicode_ci')->comment('Instruções e escopo detalhado da tarefa');
            $table->enum('task_type', ['feature', 'bug', 'refactor', 'documentation', 'chore'])->collation('utf8mb4_unicode_ci')->default('feature')->comment('Tipo da tarefa');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->collation('utf8mb4_unicode_ci')->default('medium')->comment('Prioridade de entrega');
            $table->enum('status', ['todo', 'in_progress', 'review', 'done', 'archived'])->collation('utf8mb4_unicode_ci')->default('todo')->comment('Status de fluxo');
            $table->bigInteger('assigned_user_id')->unsigned()->nullable()->comment('Desenvolvedor responsável atribuído');
            $table->bigInteger('reporter_user_id')->unsigned()->comment('Criador/autor da tarefa');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento');
            $table->dateTime('archived_at')->nullable()->comment('Momento do arquivamento');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['project_id', 'status'], 'idx_tasks_project_status');
            $table->index(['assigned_user_id'], 'idx_tasks_assigned');
            $table->index(['reporter_user_id'], 'idx_tasks_reporter');
            $table->comment('Quadro de tarefas do projeto');
        });
        DB::statement('ALTER TABLE `tasks` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

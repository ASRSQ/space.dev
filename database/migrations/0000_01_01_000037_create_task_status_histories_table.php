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
        Schema::create('task_status_histories', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('task_id')->unsigned()->comment('Tarefa auditada');
            $table->bigInteger('changed_by_user_id')->unsigned()->comment('Usuário que alterou o status');
            $table->string('old_status', 50)->collation('utf8mb4_unicode_ci')->comment('Status anterior');
            $table->string('new_status', 50)->collation('utf8mb4_unicode_ci')->comment('Novo status');
            $table->string('reason', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('Motivo opcional da transição');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['task_id'], 'idx_tsh_task');
            $table->index(['changed_by_user_id'], 'idx_tsh_user');
            $table->comment('Histórico de transições de status de tarefas');
        });
        DB::statement('ALTER TABLE `task_status_histories` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

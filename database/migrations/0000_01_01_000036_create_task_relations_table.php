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
        Schema::create('task_relations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('source_task_id')->unsigned()->comment('Tarefa de origem');
            $table->bigInteger('target_task_id')->unsigned()->comment('Tarefa de destino');
            $table->enum('relation_type', ['blocks', 'blocked_by', 'relates_to', 'duplicates'])->collation('utf8mb4_unicode_ci')->comment('Tipo do vínculo');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['source_task_id', 'target_task_id', 'relation_type'], 'uq_task_relation');
            $table->index(['target_task_id'], 'idx_tr_target');
            $table->comment('Dependências e correlações entre tarefas (sem autorrelacionamento)');
        });
        DB::statement('ALTER TABLE `task_relations` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

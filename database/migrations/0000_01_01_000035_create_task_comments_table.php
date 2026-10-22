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
        Schema::create('task_comments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('task_id')->unsigned()->comment('Tarefa comentada');
            $table->bigInteger('user_id')->unsigned()->comment('Autor do comentário');
            $table->text('comment_text')->collation('utf8mb4_unicode_ci')->comment('Texto do comentário');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['task_id'], 'idx_tc_task');
            $table->index(['user_id'], 'idx_tc_user');
            $table->comment('Comentários e discussões em tarefas');
        });
        DB::statement('ALTER TABLE `task_comments` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

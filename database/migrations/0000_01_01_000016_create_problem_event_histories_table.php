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
        Schema::create('problem_event_histories', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('problem_id')->unsigned()->comment('Problema auditado');
            $table->bigInteger('user_id')->unsigned()->comment('Usuário que desencadeou o evento');
            $table->string('event_type', 50)->collation('utf8mb4_unicode_ci')->comment('Tipo do evento (created, diagnosed, status_change, etc.)');
            $table->text('notes')->nullable()->collation('utf8mb4_unicode_ci')->comment('Notas explicativas do evento');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['problem_id'], 'idx_peh_problem');
            $table->index(['user_id'], 'idx_peh_user');
            $table->comment('Linha do tempo de ocorrências do problema');
        });
        DB::statement('ALTER TABLE `problem_event_histories` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

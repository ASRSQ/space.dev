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
        Schema::create('problem_diagnoses', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('problem_id')->unsigned()->comment('Problema analisado');
            $table->bigInteger('author_user_id')->unsigned()->comment('Especialista que elaborou o diagnóstico');
            $table->mediumText('root_cause')->collation('utf8mb4_unicode_ci')->comment('Causa raiz identificada');
            $table->text('reproduction_steps')->nullable()->collation('utf8mb4_unicode_ci')->comment('Passos de reprodução do problema');
            $table->text('proposed_action')->nullable()->collation('utf8mb4_unicode_ci')->comment('Ação de mitigação sugerida');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['problem_id'], 'idx_diagnoses_problem');
            $table->index(['author_user_id'], 'idx_diagnoses_author');
            $table->comment('Diagnósticos técnicos de causas-raízes');
        });
        DB::statement('ALTER TABLE `problem_diagnoses` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

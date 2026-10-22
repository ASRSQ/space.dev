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
        Schema::create('solution_validations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('solution_id')->unsigned()->comment('Solução validada');
            $table->bigInteger('solution_version_id')->unsigned()->nullable()->comment('Versão pontual testada');
            $table->bigInteger('validated_by_user_id')->unsigned()->comment('Engenheiro que realizou o teste/validação');
            $table->enum('outcome', ['passed', 'failed', 'inconclusive'])->collation('utf8mb4_unicode_ci')->comment('Resultado da validação');
            $table->mediumText('test_log')->collation('utf8mb4_unicode_ci')->comment('Evidências do teste, saída de logs ou observações');
            $table->dateTime('validated_at')->useCurrent();
            $table->index(['solution_id'], 'idx_sol_val_solution');
            $table->index(['solution_version_id'], 'idx_sol_val_version');
            $table->index(['validated_by_user_id'], 'idx_sol_val_validator');
            $table->comment('Histórico de testes e validações práticas de soluções');
        });
        DB::statement('ALTER TABLE `solution_validations` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

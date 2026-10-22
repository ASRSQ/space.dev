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
        Schema::create('solution_versions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('solution_id')->unsigned()->comment('Solução pai');
            $table->integer('version_number')->unsigned()->comment('Número da versão');
            $table->mediumText('solution_steps_markdown')->collation('utf8mb4_unicode_ci')->comment('Passo a passo detalhado de resolução');
            $table->mediumText('code_snippet')->nullable()->collation('utf8mb4_unicode_ci')->comment('Scripts, patches ou blocos de código aplicáveis');
            $table->text('rollback_plan')->nullable()->collation('utf8mb4_unicode_ci')->comment('Plano de reversão em caso de insucesso');
            $table->text('changelog')->nullable()->collation('utf8mb4_unicode_ci')->comment('Motivo e alterações da nova versão');
            $table->bigInteger('created_by_user_id')->unsigned()->comment('Autor da versão');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['solution_id', 'version_number'], 'uq_sol_ver_number');
            $table->index(['created_by_user_id'], 'idx_sol_ver_author');
            $table->comment('Versões históricas com passos e plano de reversão de soluções');
        });
        DB::statement('ALTER TABLE `solution_versions` ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE `solution_versions` ADD UNIQUE KEY `uq_sol_ver_pair` (`id`,`solution_id`) COMMENT \'Necessário para integridade referencial composta\'');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

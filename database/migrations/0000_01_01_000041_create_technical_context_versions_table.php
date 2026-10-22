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
        Schema::create('technical_context_versions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('technical_context_id')->unsigned()->comment('Contexto técnico pai');
            $table->integer('version_number')->unsigned()->comment('Número da versão');
            $table->mediumText('architecture_summary')->collation('utf8mb4_unicode_ci')->comment('Visão geral da arquitetura técnica');
            $table->json('tech_stack_json')->nullable()->comment('Estrutura detalhada da stack técnica');
            $table->mediumText('conventions_markdown')->nullable()->collation('utf8mb4_unicode_ci')->comment('Convenções de código e padrões adotados');
            $table->text('changelog')->nullable()->collation('utf8mb4_unicode_ci')->comment('Motivo e alterações da versão');
            $table->bigInteger('created_by_user_id')->unsigned()->comment('Usuário que elaborou a versão');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['technical_context_id', 'version_number'], 'uq_tc_versions_number');
            $table->index(['created_by_user_id'], 'idx_tc_versions_author');
            $table->comment('Histórico de versões de contextos técnicos');
        });
        DB::statement('ALTER TABLE `technical_context_versions` ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE `technical_context_versions` ADD UNIQUE KEY `uq_tc_versions_pair` (`id`,`technical_context_id`) COMMENT \'Necessário para FK composta de integridade de versão\'');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

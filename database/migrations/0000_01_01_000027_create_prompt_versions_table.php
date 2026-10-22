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
        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('prompt_template_id')->unsigned()->comment('Template do prompt');
            $table->integer('version_number')->unsigned()->comment('Número sequencial da versão');
            $table->text('system_prompt')->nullable()->collation('utf8mb4_unicode_ci')->comment('Instruções do sistema (system instructions)');
            $table->mediumText('user_prompt_template')->collation('utf8mb4_unicode_ci')->comment('Template da mensagem do usuário com {{variaveis}}');
            $table->json('model_parameters_json')->nullable()->comment('Parâmetros recomendados (temperature, top_p, max_tokens)');
            $table->text('changelog')->nullable()->collation('utf8mb4_unicode_ci')->comment('Resumo das alterações');
            $table->bigInteger('created_by_user_id')->unsigned()->comment('Autor da versão');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['prompt_template_id', 'version_number'], 'uq_pv_versions_number');
            $table->index(['created_by_user_id'], 'idx_pv_author');
            $table->comment('Histórico imutável de versões de prompts');
        });
        DB::statement('ALTER TABLE `prompt_versions` ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE `prompt_versions` ADD UNIQUE KEY `uq_pv_versions_pair` (`id`,`prompt_template_id`) COMMENT \'Necessário para FK composta de integridade de versão\'');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

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
        Schema::create('prompt_variables', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('prompt_template_id')->unsigned()->comment('Template pai da variável');
            $table->bigInteger('prompt_version_id')->unsigned()->nullable()->comment('Versão específica a que se aplica (ou NULL para todas as versões)');
            $table->string('variable_name', 100)->collation('utf8mb4_unicode_ci')->comment('Nome do placeholder (ex: code_snippet, user_goal)');
            $table->text('default_value')->nullable()->collation('utf8mb4_unicode_ci')->comment('Valor padrão opcional');
            $table->boolean('is_required')->default('1')->comment('Flag de obrigatoriedade');
            $table->string('description', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('Instrução sobre o preenchimento da variável');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['prompt_template_id', 'prompt_version_id', 'variable_name'], 'uq_pv_var_name');
            $table->index(['prompt_version_id'], 'idx_pv_ver');
            $table->comment('Variáveis dinâmicas para interpolação de prompts');
        });
        DB::statement('ALTER TABLE `prompt_variables` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

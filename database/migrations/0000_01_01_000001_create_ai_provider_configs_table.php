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
        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->string('provider_name', 50)->collation('utf8mb4_unicode_ci')->comment('Identificador do provedor (google_gemini, anthropic, openai)');
            $table->string('model_alias', 100)->collation('utf8mb4_unicode_ci')->comment('Alias do modelo utilizado');
            $table->text('api_key_encrypted')->collation('utf8mb4_unicode_ci')->comment('Chave de API cifrada. NUNCA armazene chaves em texto puro!');
            $table->string('key_vault_reference', 150)->nullable()->collation('utf8mb4_unicode_ci')->comment('Apelido ou caminho no Key Vault / Secrets Manager');
            $table->boolean('is_active')->default('1')->comment('Flag de ativação');
            $table->integer('rate_limit_rpm')->unsigned()->default('60')->comment('Limite de requisições por minuto');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['provider_name', 'model_alias'], 'uq_provider_model');
            $table->comment('Configurações de modelos e credenciais criptografadas de provedores de IA');
        });
        DB::statement('ALTER TABLE `ai_provider_configs` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

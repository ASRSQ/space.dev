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
        Schema::create('github_connections', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto vinculado');
            $table->string('repository_owner', 100)->collation('utf8mb4_unicode_ci')->comment('Organização ou usuário dono do repo');
            $table->string('repository_name', 100)->collation('utf8mb4_unicode_ci')->comment('Nome do repositório');
            $table->bigInteger('installation_id')->unsigned()->comment('ID da GitHub App Installation');
            $table->text('access_token_encrypted')->collation('utf8mb4_unicode_ci')->comment('Token cifrado com chave KMS/AES-256-GCM. NUNCA armazene em texto puro!');
            $table->string('token_key_id', 50)->collation('utf8mb4_unicode_ci')->comment('Identificador da chave no KMS/Vault para decriptação');
            $table->dateTime('token_expires_at')->nullable()->comment('Expiração do access token da GitHub App');
            $table->boolean('is_active')->default('1')->comment('Flag de conexão ativa');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['project_id'], 'uq_gh_conn_project');
            $table->comment('Metadados de integração GitHub com tokens estritamente criptografados');
        });
        DB::statement('ALTER TABLE `github_connections` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

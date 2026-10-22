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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('user_id')->unsigned()->nullable()->comment('Usuário autor da ação (NULL para ações de sistema/serviço)');
            $table->bigInteger('project_id')->unsigned()->nullable()->comment('Projeto contextual (se aplicável)');
            $table->string('action', 100)->collation('utf8mb4_unicode_ci')->comment('Nome canônico da ação (ex: membership.revoked, document.approved)');
            $table->string('resource_type', 50)->collation('utf8mb4_unicode_ci')->comment('Tipo da entidade afetada (users, projects, documents, solutions)');
            $table->bigInteger('resource_id')->unsigned()->comment('ID da entidade afetada');
            $table->json('old_values_json')->nullable()->comment('Valores anteriores (excluindo senhas e tokens)');
            $table->json('new_values_json')->nullable()->comment('Novos valores (excluindo senhas e tokens)');
            $table->string('ip_address', 45)->nullable()->collation('utf8mb4_unicode_ci')->comment('Endereço IP (IPv4 ou IPv6)');
            $table->string('user_agent', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('User-Agent do cliente solicitante');
            $table->dateTime('created_at')->useCurrent()->comment('Momento do evento');
            $table->index(['resource_type', 'resource_id'], 'idx_audit_resource');
            $table->index(['user_id'], 'idx_audit_user');
            $table->index(['project_id'], 'idx_audit_project');
            $table->index(['created_at'], 'idx_audit_created');
            $table->comment('Registro imutável de auditoria de operações críticas');
        });
        DB::statement('ALTER TABLE `audit_logs` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

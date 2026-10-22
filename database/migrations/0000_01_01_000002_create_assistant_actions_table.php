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
        Schema::create('assistant_actions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('conversation_id')->unsigned()->comment('Conversa originária da ação');
            $table->bigInteger('message_id')->unsigned()->nullable()->comment('Mensagem de gatilho');
            $table->string('action_type', 50)->collation('utf8mb4_unicode_ci')->comment('Tipo de ação (ex: propose_document_change, query_github, generate_task)');
            $table->string('target_resource_type', 50)->collation('utf8mb4_unicode_ci')->comment('Entidade alvo (document, technical_context, prompt, task, problem)');
            $table->bigInteger('target_resource_id')->unsigned()->comment('Identificador numérico do recurso alvo');
            $table->enum('execution_status', ['proposed', 'executed', 'rejected', 'failed'])->collation('utf8mb4_unicode_ci')->default('proposed')->comment('Status de execução da ação');
            $table->json('parameters_json')->nullable()->comment('Parâmetros enviados à ação');
            $table->json('result_json')->nullable()->comment('Resultado ou retorno gerado pela ação');
            $table->dateTime('executed_at')->nullable()->comment('Momento em que foi efetivada');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['conversation_id'], 'idx_aa_conversation');
            $table->index(['target_resource_type', 'target_resource_id'], 'idx_aa_target');
            $table->index(['execution_status'], 'idx_aa_status');
            $table->index(['message_id'], 'fk_aa_message');
            $table->comment('Auditoria de ações autônomas ou assistidas propostas pelo assistente');
        });
        DB::statement('ALTER TABLE `assistant_actions` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

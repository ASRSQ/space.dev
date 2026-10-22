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
        Schema::create('github_webhook_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->string('delivery_id', 100)->collation('utf8mb4_unicode_ci')->comment('Identificador único global do evento fornecido pelo GitHub (X-GitHub-Delivery)');
            $table->string('event_type', 50)->collation('utf8mb4_unicode_ci')->comment('Tipo do evento (push, pull_request, issues, etc.)');
            $table->string('repository_full_name', 200)->collation('utf8mb4_unicode_ci')->comment('owner/repo associado');
            $table->char('payload_hash', 64)->collation('utf8mb4_unicode_ci')->comment('Hash SHA-256 do payload recebido para integridade');
            $table->string('action', 50)->nullable()->collation('utf8mb4_unicode_ci')->comment('Ação do evento (opened, synchronize, closed)');
            $table->enum('processed_status', ['received', 'processed', 'ignored', 'failed'])->collation('utf8mb4_unicode_ci')->default('received')->comment('Estado do processamento');
            $table->text('error_message')->nullable()->collation('utf8mb4_unicode_ci')->comment('Mensagem de erro em caso de falha');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['repository_full_name', 'event_type'], 'idx_gh_events_repo_type');
            $table->index(['processed_status'], 'idx_gh_events_status');
            $table->comment('Fila e deduplicação de webhooks recebidos do GitHub');
        });
        DB::statement('ALTER TABLE `github_webhook_events` ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE `github_webhook_events` ADD UNIQUE KEY `uq_gh_delivery_id` (`delivery_id`) COMMENT \'Garante estrita deduplicação de webhooks do GitHub\'');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

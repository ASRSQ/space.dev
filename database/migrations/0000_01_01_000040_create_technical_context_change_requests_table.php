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
        Schema::create('technical_context_change_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('technical_context_id')->unsigned()->comment('Contexto técnico afetado');
            $table->integer('proposed_version_number')->unsigned()->comment('Número da versão proposta');
            $table->mediumText('proposed_architecture_summary')->collation('utf8mb4_unicode_ci')->comment('Resumo arquitetural proposto');
            $table->json('proposed_tech_stack_json')->nullable()->comment('Stack técnica proposta');
            $table->mediumText('proposed_conventions_markdown')->nullable()->collation('utf8mb4_unicode_ci')->comment('Convenções propostas');
            $table->text('reason')->collation('utf8mb4_unicode_ci')->comment('Motivação da alteração');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->collation('utf8mb4_unicode_ci')->default('pending');
            $table->bigInteger('author_user_id')->unsigned()->comment('Autor da proposta');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['technical_context_id', 'status'], 'idx_tccr_context_status');
            $table->index(['author_user_id'], 'idx_tccr_author');
            $table->comment('Solicitações de alteração em contextos técnicos');
        });
        DB::statement('ALTER TABLE `technical_context_change_requests` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

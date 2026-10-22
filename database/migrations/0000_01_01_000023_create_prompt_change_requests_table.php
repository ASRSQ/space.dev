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
        Schema::create('prompt_change_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('prompt_template_id')->unsigned()->comment('Prompt afetado');
            $table->integer('proposed_version_number')->unsigned()->comment('Número da nova versão proposta');
            $table->text('proposed_system_prompt')->nullable()->collation('utf8mb4_unicode_ci');
            $table->mediumText('proposed_user_prompt_template')->collation('utf8mb4_unicode_ci');
            $table->json('proposed_model_parameters_json')->nullable();
            $table->text('reason')->collation('utf8mb4_unicode_ci');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->collation('utf8mb4_unicode_ci')->default('pending');
            $table->bigInteger('author_user_id')->unsigned();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['prompt_template_id', 'status'], 'idx_pcr_template_status');
            $table->index(['author_user_id'], 'idx_pcr_author');
            $table->comment('Solicitações de alteração em prompts');
        });
        DB::statement('ALTER TABLE `prompt_change_requests` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

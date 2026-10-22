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
        Schema::create('prompt_change_request_decisions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('change_request_id')->unsigned();
            $table->bigInteger('decided_by_user_id')->unsigned();
            $table->enum('decision', ['approved', 'rejected'])->collation('utf8mb4_unicode_ci');
            $table->text('comments')->collation('utf8mb4_unicode_ci');
            $table->dateTime('decided_at')->useCurrent();
            $table->index(['change_request_id'], 'idx_pcrd_cr');
            $table->index(['decided_by_user_id'], 'idx_pcrd_decider');
            $table->comment('Histórico de decisões para solicitações de prompts');
        });
        DB::statement('ALTER TABLE `prompt_change_request_decisions` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

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
        Schema::create('solution_change_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('solution_id')->unsigned()->comment('Solução afetada');
            $table->integer('proposed_version_number')->unsigned()->comment('Número da nova versão proposta');
            $table->mediumText('proposed_steps_markdown')->collation('utf8mb4_unicode_ci');
            $table->mediumText('proposed_code_snippet')->nullable()->collation('utf8mb4_unicode_ci');
            $table->text('proposed_rollback_plan')->nullable()->collation('utf8mb4_unicode_ci');
            $table->text('reason')->collation('utf8mb4_unicode_ci');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->collation('utf8mb4_unicode_ci')->default('pending');
            $table->bigInteger('author_user_id')->unsigned();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['solution_id', 'status'], 'idx_scr_solution_status');
            $table->index(['author_user_id'], 'idx_scr_author');
            $table->comment('Solicitações de alteração para soluções');
        });
        DB::statement('ALTER TABLE `solution_change_requests` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

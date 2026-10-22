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
        Schema::create('problems', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto onde o problema ocorre');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título descritivo do problema');
            $table->mediumText('description')->collation('utf8mb4_unicode_ci')->comment('Descrição aprofundada dos sintomas e impacto');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->collation('utf8mb4_unicode_ci')->default('medium')->comment('Gravidade do problema');
            $table->enum('status', ['open', 'diagnosed', 'resolved', 'closed'])->collation('utf8mb4_unicode_ci')->default('open')->comment('Estado do problema');
            $table->bigInteger('reporter_user_id')->unsigned()->comment('Usuário que reportou o problema');
            $table->boolean('is_archived')->default('0')->comment('Flag de arquivamento');
            $table->dateTime('archived_at')->nullable()->comment('Momento do arquivamento');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['project_id', 'status'], 'idx_problems_proj_status');
            $table->index(['reporter_user_id'], 'idx_problems_reporter');
            $table->comment('Problemas técnicos catalogados na base de conhecimento');
        });
        DB::statement('ALTER TABLE `problems` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

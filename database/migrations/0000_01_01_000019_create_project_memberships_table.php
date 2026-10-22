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
        Schema::create('project_memberships', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto ao qual o membro foi associado');
            $table->bigInteger('user_id')->unsigned()->comment('Usuário membro');
            $table->string('role_in_project', 50)->collation('utf8mb4_unicode_ci')->default('contributor')->comment('Papel contextual no projeto (owner, lead, contributor, reviewer)');
            $table->enum('status', ['active', 'revoked'])->collation('utf8mb4_unicode_ci')->default('active')->comment('Estado da associação');
            $table->dateTime('granted_at')->useCurrent()->comment('Data/hora da concessão do acesso');
            $table->bigInteger('granted_by_user_id')->unsigned()->comment('Usuário que concedeu o acesso');
            $table->dateTime('revoked_at')->nullable()->comment('Data/hora da revogação do acesso');
            $table->bigInteger('revoked_by_user_id')->unsigned()->nullable()->comment('Usuário que revogou o acesso');
            $table->string('active_user_project_key', 64)->nullable()->collation('utf8mb4_unicode_ci')->virtualAs('if((`status` = _utf8mb4\'active\'),concat(`project_id`,_utf8mb4\':\',`user_id`),NULL)')->comment('Chave virtual para garantir unicidade apenas de membros ativos');
            $table->unique(['active_user_project_key'], 'uq_active_membership');
            $table->index(['project_id', 'user_id', 'status'], 'idx_memberships_proj_user');
            $table->index(['user_id'], 'idx_memberships_user');
            $table->index(['granted_by_user_id'], 'fk_memberships_granted_by');
            $table->index(['revoked_by_user_id'], 'fk_memberships_revoked_by');
            $table->comment('Membros e permissões por projeto com histórico de revogações');
        });
        DB::statement('ALTER TABLE `project_memberships` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

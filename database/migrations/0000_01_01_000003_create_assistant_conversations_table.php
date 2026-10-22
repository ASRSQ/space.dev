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
        Schema::create('assistant_conversations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('user_id')->unsigned()->comment('Usuário proprietário da conversa');
            $table->bigInteger('project_id')->unsigned()->nullable()->comment('Projeto contextualizado (ou NULL se conversa geral)');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título descritivo da conversa');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['user_id'], 'idx_ac_user');
            $table->index(['project_id'], 'idx_ac_project');
            $table->comment('Sessões de conversa mantidas com o assistente técnico');
        });
        DB::statement('ALTER TABLE `assistant_conversations` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

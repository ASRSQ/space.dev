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
        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('conversation_id')->unsigned()->comment('Conversa associada');
            $table->enum('sender_type', ['user', 'assistant', 'system'])->collation('utf8mb4_unicode_ci')->comment('Origem da mensagem');
            $table->mediumText('message_content')->collation('utf8mb4_unicode_ci')->comment('Conteúdo textual da mensagem');
            $table->integer('tokens_used')->unsigned()->nullable()->comment('Quantidade de tokens processados');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['conversation_id'], 'idx_am_conv');
            $table->comment('Histórico de mensagens trocadas na conversa com o assistente');
        });
        DB::statement('ALTER TABLE `assistant_messages` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

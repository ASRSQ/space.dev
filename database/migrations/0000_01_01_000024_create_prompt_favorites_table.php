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
        Schema::create('prompt_favorites', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('user_id')->unsigned()->comment('Usuário que favoritou');
            $table->bigInteger('prompt_template_id')->unsigned()->comment('Prompt favoritado');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['user_id', 'prompt_template_id'], 'uq_user_prompt_fav');
            $table->index(['prompt_template_id'], 'idx_fav_prompt');
            $table->comment('Lista de favoritos de prompts por usuário');
        });
        DB::statement('ALTER TABLE `prompt_favorites` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

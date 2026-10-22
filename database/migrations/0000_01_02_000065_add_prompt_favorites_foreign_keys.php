<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_favorites', function (Blueprint $table): void {
            $table->foreign(['prompt_template_id'], 'fk_fav_prompt')->references(['id'])->on('prompt_templates')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['user_id'], 'fk_fav_user')->references(['id'])->on('users')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

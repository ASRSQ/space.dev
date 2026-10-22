<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_versions', function (Blueprint $table): void {
            $table->foreign(['created_by_user_id'], 'fk_pv_author')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['prompt_template_id'], 'fk_pv_template')->references(['id'])->on('prompt_templates')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

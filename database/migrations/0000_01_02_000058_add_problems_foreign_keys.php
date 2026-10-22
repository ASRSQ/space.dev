<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problems', function (Blueprint $table): void {
            $table->foreign(['project_id'], 'fk_problems_project')->references(['id'])->on('projects')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['reporter_user_id'], 'fk_problems_reporter')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

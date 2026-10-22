<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign(['assigned_user_id'], 'fk_tasks_assigned')->references(['id'])->on('users')->onDelete('set null')->onUpdate('cascade');
            $table->foreign(['project_id'], 'fk_tasks_project')->references(['id'])->on('projects')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['reporter_user_id'], 'fk_tasks_reporter')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

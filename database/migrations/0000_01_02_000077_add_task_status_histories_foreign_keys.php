<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_status_histories', function (Blueprint $table): void {
            $table->foreign(['task_id'], 'fk_tsh_task')->references(['id'])->on('tasks')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['changed_by_user_id'], 'fk_tsh_user')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table): void {
            $table->foreign(['user_id'], 'fk_tc_author')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['task_id'], 'fk_tc_task')->references(['id'])->on('tasks')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

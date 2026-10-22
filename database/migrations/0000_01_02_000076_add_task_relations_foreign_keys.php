<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_relations', function (Blueprint $table): void {
            $table->foreign(['source_task_id'], 'fk_tr_source')->references(['id'])->on('tasks')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['target_task_id'], 'fk_tr_target')->references(['id'])->on('tasks')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

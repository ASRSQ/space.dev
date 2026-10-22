<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            throw new RuntimeException('Task deadlines require the domain baseline first (tasks is missing). Verify the installation package. No schema changes were made.');
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['due_date']);
            $table->dropColumn(['start_date', 'due_date', 'completed_at']);
        });
    }
};

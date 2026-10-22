<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solution_change_request_decisions', function (Blueprint $table): void {
            $table->foreign(['decided_by_user_id'], 'fk_scrd_decider')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['change_request_id'], 'fk_scrd_request')->references(['id'])->on('solution_change_requests')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

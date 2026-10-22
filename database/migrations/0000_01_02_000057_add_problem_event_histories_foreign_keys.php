<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problem_event_histories', function (Blueprint $table): void {
            $table->foreign(['problem_id'], 'fk_peh_problem')->references(['id'])->on('problems')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['user_id'], 'fk_peh_user')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

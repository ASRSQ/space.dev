<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problem_diagnoses', function (Blueprint $table): void {
            $table->foreign(['author_user_id'], 'fk_diagnoses_author')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['problem_id'], 'fk_diagnoses_problem')->references(['id'])->on('problems')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

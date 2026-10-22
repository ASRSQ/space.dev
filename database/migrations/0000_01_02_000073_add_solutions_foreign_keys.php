<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table): void {
            $table->foreign(['author_user_id'], 'fk_solutions_author')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['current_version_id', 'id'], 'fk_solutions_current_version_pair')->references(['id', 'solution_id'])->on('solution_versions')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['problem_id'], 'fk_solutions_problem')->references(['id'])->on('problems')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

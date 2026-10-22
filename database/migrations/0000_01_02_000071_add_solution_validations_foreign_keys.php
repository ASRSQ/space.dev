<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solution_validations', function (Blueprint $table): void {
            $table->foreign(['solution_id'], 'fk_sol_val_solution')->references(['id'])->on('solutions')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['validated_by_user_id'], 'fk_sol_val_validator')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['solution_version_id'], 'fk_sol_val_version')->references(['id'])->on('solution_versions')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

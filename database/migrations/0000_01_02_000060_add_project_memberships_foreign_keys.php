<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_memberships', function (Blueprint $table): void {
            $table->foreign(['granted_by_user_id'], 'fk_memberships_granted_by')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['project_id'], 'fk_memberships_project')->references(['id'])->on('projects')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['revoked_by_user_id'], 'fk_memberships_revoked_by')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['user_id'], 'fk_memberships_user')->references(['id'])->on('users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

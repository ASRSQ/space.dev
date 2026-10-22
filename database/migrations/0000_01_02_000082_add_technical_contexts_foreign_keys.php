<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_contexts', function (Blueprint $table): void {
            $table->foreign(['current_version_id', 'id'], 'fk_tech_ctx_current_version_pair')->references(['id', 'technical_context_id'])->on('technical_context_versions')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['project_id'], 'fk_tech_ctx_project')->references(['id'])->on('projects')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

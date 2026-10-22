<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreign(['category_id'], 'fk_documents_category')->references(['id'])->on('categories')->onDelete('set null')->onUpdate('cascade');
            $table->foreign(['current_version_id', 'id'], 'fk_documents_current_version_pair')->references(['id', 'document_id'])->on('document_versions')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign(['project_id'], 'fk_documents_project')->references(['id'])->on('projects')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

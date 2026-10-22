<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_tags', function (Blueprint $table): void {
            $table->foreign(['document_id'], 'fk_doc_tags_document')->references(['id'])->on('documents')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['tag_id'], 'fk_doc_tags_tag')->references(['id'])->on('tags')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

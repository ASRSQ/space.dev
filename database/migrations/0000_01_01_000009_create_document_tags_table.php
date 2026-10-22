<?php

use App\Support\DomainMigrationPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DomainMigrationPlatform::assertSupported();
        Schema::create('document_tags', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('document_id')->unsigned()->comment('Documento associado');
            $table->bigInteger('tag_id')->unsigned()->comment('Tag vinculada');
            $table->dateTime('created_at')->useCurrent();
            $table->primary(['document_id', 'tag_id'], 'PRIMARY');
            $table->index(['tag_id'], 'idx_doc_tags_tag');
            $table->comment('Tabela pivô entre documentos e tags');
        });
        DB::statement('ALTER TABLE `document_tags` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

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
        Schema::create('solutions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('problem_id')->unsigned()->comment('Problema resolvido pela solução');
            $table->string('title', 200)->collation('utf8mb4_unicode_ci')->comment('Título sintetizado da solução');
            $table->bigInteger('current_version_id')->unsigned()->nullable()->comment('Versão oficial atual da solução');
            $table->enum('status', ['draft', 'active', 'deprecated'])->collation('utf8mb4_unicode_ci')->default('draft')->comment('Estado da solução');
            $table->bigInteger('author_user_id')->unsigned()->comment('Autor da solução');
            $table->boolean('is_archived')->default('0');
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['problem_id'], 'idx_solutions_problem');
            $table->index(['current_version_id'], 'idx_solutions_current_ver');
            $table->index(['author_user_id'], 'idx_solutions_author');
            $table->index(['current_version_id', 'id'], 'fk_solutions_current_version_pair');
            $table->comment('Soluções técnicas vinculadas a problemas');
        });
        DB::statement('ALTER TABLE `solutions` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

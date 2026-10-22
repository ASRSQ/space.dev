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
        Schema::create('project_technologies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto que utiliza a tecnologia');
            $table->string('name', 100)->collation('utf8mb4_unicode_ci')->comment('Nome da tecnologia ou biblioteca');
            $table->string('version_specified', 50)->nullable()->collation('utf8mb4_unicode_ci')->comment('Versão declarada ou utilizada');
            $table->string('category', 50)->collation('utf8mb4_unicode_ci')->comment('Categoria técnica (ex: language, framework, database, cache)');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['project_id', 'name'], 'uq_project_tech');
            $table->comment('Matriz tecnológica associada a cada projeto');
        });
        DB::statement('ALTER TABLE `project_technologies` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

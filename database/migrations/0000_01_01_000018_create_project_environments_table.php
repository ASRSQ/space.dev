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
        Schema::create('project_environments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto correspondente');
            $table->string('name', 50)->collation('utf8mb4_unicode_ci')->comment('Nome identificador do ambiente (ex: local, staging, production)');
            $table->enum('env_type', ['development', 'staging', 'production', 'testing'])->collation('utf8mb4_unicode_ci')->comment('Tipo do ambiente');
            $table->string('base_url', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('URL base ou hostname');
            $table->boolean('is_active')->default('1')->comment('Flag indicativa de ambiente ativo');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['project_id', 'name'], 'uq_project_env_name');
            $table->comment('Configurações de ambientes de deploy e execução');
        });
        DB::statement('ALTER TABLE `project_environments` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

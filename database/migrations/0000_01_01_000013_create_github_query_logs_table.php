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
        Schema::create('github_query_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('project_id')->unsigned()->comment('Projeto que disparou a consulta');
            $table->string('endpoint', 255)->collation('utf8mb4_unicode_ci')->comment('Endpoint da API do GitHub consultado (ex: /repos/{owner}/{repo}/commits)');
            $table->string('http_method', 10)->collation('utf8mb4_unicode_ci')->default('GET')->comment('Verbo HTTP (restrito a leituras)');
            $table->string('request_summary', 255)->collation('utf8mb4_unicode_ci')->comment('Sumário da requisição (estritamente sem tokens ou segredos)');
            $table->smallInteger('http_status')->unsigned()->comment('Código HTTP retornado pelo GitHub');
            $table->integer('response_time_ms')->unsigned()->comment('Latência da consulta em milissegundos');
            $table->dateTime('created_at')->useCurrent();
            $table->index(['project_id'], 'idx_gql_project');
            $table->index(['created_at'], 'idx_gql_created');
            $table->comment('Auditoria técnica de consultas à API do GitHub (somente leitura)');
        });
        DB::statement('ALTER TABLE `github_query_logs` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};

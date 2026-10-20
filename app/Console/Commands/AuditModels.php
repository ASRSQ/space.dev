<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class AuditModels extends Command
{
    protected $signature = 'models:audit';

    protected $description =
        'Audita Models Laravel contra o esquema real do MySQL';

    public function handle(): int
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        if ($database !== 'spacedev_test') {
            $this->error("Banco inesperado: {$database}");
            return self::FAILURE;
        }

        $tables = collect(DB::select(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = 'BASE TABLE'",
            [$database]
        ))->pluck('TABLE_NAME')->all();

        $modelsPath = app_path('Models');

        $files = File::glob($modelsPath . DIRECTORY_SEPARATOR . '*.php');

        $modelTables = [];
        $errors = [];
        $warnings = [];
        $checked = 0;

        foreach ($files as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            try {
                if (!class_exists($class)) {
                    $errors[] = "{$class}: classe não carregável";
                    continue;
                }

                if (!is_subclass_of($class, Model::class)) {
                    $warnings[] = "{$class}: não estende Eloquent Model";
                    continue;
                }

                /** @var Model $model */
                $model = new $class();
                $table = $model->getTable();

                if (in_array($table, $modelTables, true)) {
                    $warnings[] = "{$class}: tabela duplicada {$table}";
                }

                $modelTables[] = $table;

                if (!in_array($table, $tables, true)) {
                    $errors[] = "{$class}: tabela inexistente {$table}";
                    continue;
                }

                $columns = collect(DB::select(
                    "SELECT COLUMN_NAME
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ?
                       AND TABLE_NAME = ?",
                    [$database, $table]
                ))->pluck('COLUMN_NAME')->all();

                $missingFillable = array_diff(
                    $model->getFillable(),
                    $columns
                );

                foreach ($missingFillable as $column) {
                    $errors[] =
                        "{$class}: fillable '{$column}' não existe em {$table}";
                }

                $timestamps = $model->usesTimestamps();

                if ($timestamps) {
                    foreach ([
                        $model->getCreatedAtColumn(),
                        $model->getUpdatedAtColumn(),
                    ] as $timestamp) {
                        if (
                            $timestamp !== null
                            && !in_array($timestamp, $columns, true)
                        ) {
                            $errors[] =
                                "{$class}: timestamp '{$timestamp}' ausente em {$table}";
                        }
                    }
                }

                $checked++;
                $this->info("OK: {$class} → {$table}");
            } catch (Throwable $e) {
                $errors[] = "{$class}: " . $e->getMessage();
            }
        }

        $missingModels = array_diff($tables, $modelTables);

        foreach ($missingModels as $table) {
            $warnings[] = "Tabela sem Model: {$table}";
        }

        $this->newLine();
        $this->info("Banco: {$database}");
        $this->info('Tabelas: ' . count($tables));
        $this->info('Arquivos de Model: ' . count($files));
        $this->info("Models auditados: {$checked}");
        $this->info('Erros: ' . count($errors));
        $this->info('Avisos: ' . count($warnings));

        foreach ($errors as $error) {
            $this->error($error);
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return count($errors) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
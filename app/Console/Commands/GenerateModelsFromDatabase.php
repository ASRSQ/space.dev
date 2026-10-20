<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateModelsFromDatabase extends Command
{
    protected $signature = 'make:models-from-db
                            {--dry-run : Apenas listar o que seria criado}';

    protected $description =
        'Gera Models a partir do esquema MySQL sem alterar o banco';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();

        if ($database !== 'spacedev_test') {
            $this->error(
                "Banco inesperado: {$database}. Operação cancelada."
            );

            return self::FAILURE;
        }

        $tables = DB::select(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
            [$database]
        );

        $foreignKeys = DB::select(
            "SELECT
                TABLE_NAME,
                COLUMN_NAME,
                CONSTRAINT_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION",
            [$database]
        );

        // Agrupa as colunas de cada FK para identificar chaves compostas.
        $groups = [];

        foreach ($foreignKeys as $fk) {
            $key = $fk->TABLE_NAME . '.' . $fk->CONSTRAINT_NAME;
            $groups[$key][] = $fk;
        }

        $simpleKeys = [];
        $compositeKeys = [];

        foreach ($groups as $key => $columns) {
            if (count($columns) === 1) {
                $simpleKeys[] = $columns[0];
            } else {
                $compositeKeys[] = $key;
            }
        }

        $created = 0;
        $existing = 0;
        $errors = 0;

        $this->info("Banco: {$database}");
        $this->info('Tabelas encontradas: ' . count($tables));

        foreach ($tables as $tableInfo) {
            $table = $tableInfo->TABLE_NAME;
            $class = Str::studly(Str::singular($table));
            $path = app_path("Models/{$class}.php");

            if (File::exists($path)) {
                $this->line("EXISTENTE: {$class} ({$table})");
                $existing++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("CRIARIA: {$class} ({$table})");
                continue;
            }

            try {
                $columns = DB::select(
                    "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
                            IS_NULLABLE, EXTRA
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ?
                       AND TABLE_NAME = ?
                     ORDER BY ORDINAL_POSITION",
                    [$database, $table]
                );

                $fillable = [];
                $casts = [];

                foreach ($columns as $column) {
                    $name = $column->COLUMN_NAME;
                    $type = strtolower($column->DATA_TYPE);

                    if (!in_array($name, [
                        'id', 'created_at', 'updated_at',
                    ], true)) {
                        $fillable[] = $name;
                    }

                    if (
                        $type === 'tinyint'
                        && str_contains(
                            strtolower($column->COLUMN_TYPE),
                            'tinyint(1)'
                        )
                    ) {
                        $casts[$name] = 'boolean';
                    } elseif (in_array(
                        $type,
                        ['datetime', 'timestamp'],
                        true
                    )) {
                        $casts[$name] = 'datetime';
                    } elseif ($type === 'date') {
                        $casts[$name] = 'date';
                    } elseif ($type === 'json') {
                        $casts[$name] = 'array';
                    } elseif (in_array(
                        $type,
                        ['decimal', 'float', 'double'],
                        true
                    )) {
                        $casts[$name] = 'float';
                    }
                }

                $belongsTo = [];
                $imports = [
                    'Illuminate\\Database\\Eloquent\\Model',
                ];

                foreach ($simpleKeys as $fk) {
                    if ($fk->TABLE_NAME !== $table) {
                        continue;
                    }

                    $method = Str::camel(
                        preg_replace('/_id$/', '', $fk->COLUMN_NAME)
                    );

                    $related = Str::studly(
                        Str::singular($fk->REFERENCED_TABLE_NAME)
                    );

                    if (!class_exists(
                        "App\\Models\\{$related}"
                    ) && !File::exists(
                        app_path("Models/{$related}.php")
                    )) {
                        // A classe poderá ser gerada posteriormente.
                    }

                    $belongsTo[$method] = [
                        'class' => $related,
                        'column' => $fk->COLUMN_NAME,
                    ];
                }

                if ($belongsTo !== []) {
                    $imports[] =
                        'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
                }

                $lines = [
                    '<?php',
                    '',
                    'namespace App\\Models;',
                    '',
                ];

                foreach ($imports as $import) {
                    $lines[] = "use {$import};";
                }

                $lines[] = '';
                $lines[] = "class {$class} extends Model";
                $lines[] = '{';
                $lines[] = "    protected \$table = '{$table}';";
                $lines[] = '';
                $lines[] = '    protected $fillable = [';

                foreach ($fillable as $field) {
                    $lines[] = "        '{$field}',";
                }

                $lines[] = '    ];';
                $lines[] = '';

                $hasCreatedAt = false;
                $hasUpdatedAt = false;

                foreach ($columns as $column) {
                    $hasCreatedAt |=
                        $column->COLUMN_NAME === 'created_at';
                    $hasUpdatedAt |=
                        $column->COLUMN_NAME === 'updated_at';
                }

                if (!$hasCreatedAt || !$hasUpdatedAt) {
                    $lines[] = '    public $timestamps = false;';
                    $lines[] = '';
                }

                if ($casts !== []) {
                    $lines[] = '    protected function casts(): array';
                    $lines[] = '    {';
                    $lines[] = '        return [';

                    foreach ($casts as $field => $cast) {
                        $lines[] =
                            "            '{$field}' => '{$cast}',";
                    }

                    $lines[] = '        ];';
                    $lines[] = '    }';
                    $lines[] = '';
                }

                foreach ($belongsTo as $method => $relation) {
                    $related = $relation['class'];
                    $column = $relation['column'];

                    $lines[] =
                        "    public function {$method}(): BelongsTo";
                    $lines[] = '    {';
                    $lines[] =
                        "        return \$this->belongsTo({$related}::class, '{$column}');";
                    $lines[] = '    }';
                    $lines[] = '';
                }

                $lines[] = '}';

                File::ensureDirectoryExists(dirname($path));
                File::put($path, implode("\n", $lines) . "\n");

                $this->info("CRIADO: {$class} ({$table})");
                $created++;
            } catch (\Throwable $e) {
                $this->error(
                    "ERRO em {$table}: {$e->getMessage()}"
                );
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Models criados: {$created}");
        $this->info("Models já existentes: {$existing}");
        $this->info("Erros: {$errors}");
        $this->info(
            'FKs simples: ' . count($simpleKeys)
        );
        $this->info(
            'FKs compostas para revisão: ' . count($compositeKeys)
        );

        foreach ($compositeKeys as $key) {
            $this->warn("Revisar FK composta: {$key}");
        }

        if ($this->option('dry-run')) {
            $this->comment(
                'Simulação concluída. Nenhum arquivo foi criado.'
            );
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Throwable;

class TestModelsQueries extends Command
{
    protected $signature = 'models:smoke-test';

    protected $description =
        'Testa consultas de leitura de todos os Models';

    public function handle(): int
    {
        $database = config('database.connections.mysql.database');

        if ($database !== 'spacedev_test') {
            $this->error("Banco inesperado: {$database}");
            return self::FAILURE;
        }

        $files = File::glob(app_path('Models/*.php'));
        $passed = 0;
        $failed = 0;

        foreach ($files as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            try {
                if (!class_exists($class)
                    || !is_subclass_of($class, Model::class)) {
                    throw new \RuntimeException(
                        'Classe inválida ou não é um Model Eloquent'
                    );
                }

                $model = new $class();

                // Executa apenas uma consulta de leitura.
                $model->newQuery()->limit(1)->get();

                $this->info("PASSOU: {$class}");
                $passed++;
            } catch (Throwable $e) {
                $this->error(
                    "FALHOU: {$class} — {$e->getMessage()}"
                );
                $failed++;
            }
        }

        $this->newLine();
        $this->info('Models testados: ' . count($files));
        $this->info("Aprovados: {$passed}");
        $this->info("Falhas: {$failed}");

        return $failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
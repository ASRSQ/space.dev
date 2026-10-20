<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function baseline(): array
    {
        return json_decode(file_get_contents(database_path('schema/space-baseline.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('The SPACE.dev production baseline requires MySQL.');
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.16', '<')) {
            throw new RuntimeException('SPACE.dev requires MySQL >= 8.0.16 with functional indexes. MariaDB is not supported by this migration chain.');
        }

        $baseline = $this->baseline();
        // Do not silently adopt an existing or partially installed domain schema.
        foreach (array_keys($baseline['tables']) as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Clean installation requires absent domain tables; found {$table}. Do not run this baseline on an existing installation.");
            }
        }
        foreach ($baseline['tables'] as $sql) {
            DB::unprepared($sql);
        }
        // All referenced tables and unique keys now exist, including version cycles.
        foreach ($baseline['foreign_keys'] as $sql) {
            DB::unprepared($sql);
        }
        foreach ($baseline['triggers'] as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Domain baseline rollback is intentionally blocked to protect application data. Restore a verified backup or recreate an explicitly disposable database.');
    }
};

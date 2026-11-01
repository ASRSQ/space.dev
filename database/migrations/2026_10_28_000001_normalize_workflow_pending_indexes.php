<?php

use App\Support\DomainMigrationPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DomainMigrationPlatform::assertSupported();
        $plans = [];
        foreach ([
            ['document_change_requests', 'document_id', 'pending_document_id', 'uq_dcr_pending_document'],
            ['technical_context_change_requests', 'technical_context_id', 'pending_technical_context_id', 'uq_tccr_pending_context'],
        ] as [$table, $entity, $column, $index]) {
            $ddl = ((array) DB::selectOne("SHOW CREATE TABLE `$table`"))['Create Table'];
            // information_schema may escape quotes in GENERATION_EXPRESSION;
            // SHOW CREATE renders the same ASCII literal without those escapes.
            $compact = static function (string $sql): string {
                $parts = preg_split("/('[^']*')/", str_replace("\\'", "'", $sql), -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($parts as $i => &$part) {
                    if ($i % 2 === 0) { $part = strtolower(preg_replace('/\s+/', '', str_replace(['`', '_utf8mb4'], '', $part))); }
                }
                unset($part);
                return implode('', $parts);
            };
            $expression = "(case when (`status` = _utf8mb4'pending') then `$entity` else NULL end)";
            $columns = DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
            $indexes = array_values(array_filter(DB::select("SHOW INDEX FROM `$table`"), fn ($i) => $i->Key_name === $index));
            if (count($indexes) !== 1 || (int) $indexes[0]->Non_unique !== 0 || (int) $indexes[0]->Seq_in_index !== 1 || $indexes[0]->Sub_part !== null || $indexes[0]->Index_type !== 'BTREE'
                || (isset($indexes[0]->Visible) && $indexes[0]->Visible !== 'YES')) {
                throw new RuntimeException("Unexpected workflow index: $table.$index. No automatic repair of unknown structures.");
            }
            if ($columns !== []) {
                $c = $columns[0];
                if ($indexes[0]->Column_name !== $column || ! preg_match('/^bigint(?:\(20\))? unsigned$/', $c->COLUMN_TYPE)
                    || $c->IS_NULLABLE !== 'YES' || $c->EXTRA !== 'VIRTUAL GENERATED' || $compact($c->GENERATION_EXPRESSION) !== $compact($expression)) {
                    throw new RuntimeException("Unexpected generated workflow column: $table.$column.");
                }
                continue; // Fresh installations already have the portable representation.
            }
            if ($indexes[0]->Column_name !== null || ! preg_match('/^  UNIQUE KEY `'.preg_quote($index, '/').'` \(\((.+)\)\),?$/m', $ddl, $match)
                || $compact($match[1]) !== $compact($expression)) {
                throw new RuntimeException("Unexpected functional workflow expression: $table.$index.");
            }
            if (DB::table($table)->select($entity)->where('status', 'pending')->groupBy($entity)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException("Duplicate pending requests: $table. Data will not be modified.");
            }
            $temporary = $index.'_portable';
            if (array_filter(DB::select("SHOW INDEX FROM `$table`"), fn ($i) => $i->Key_name === $temporary)) {
                throw new RuntimeException("Partial conversion detected: $table.$temporary. Inspect before continuing.");
            }
            $plans[] = [$table, $entity, $column, $index, $temporary];
        }
        // Validate both tables before any DDL. Keep the old unique index until
        // the replacement exists; never leave pending reviews unprotected.
        foreach ($plans as [$table, $entity, $column, $index, $temporary]) {
            // MySQL 5.7 cannot combine ADD VIRTUAL COLUMN with other ALTER
            // actions in-place. MySQL 8 may need its COPY algorithm while the
            // legacy functional index exists; let the server choose for ADD.
            DB::statement("ALTER TABLE `$table` ADD COLUMN `$column` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN `$entity` ELSE NULL END) VIRTUAL");
            DB::statement("ALTER TABLE `$table` ADD UNIQUE INDEX `$temporary` (`$column`), ALGORITHM=INPLACE");
            DB::statement("ALTER TABLE `$table` DROP INDEX `$index`, RENAME INDEX `$temporary` TO `$index`");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback blocked: preserve workflow uniqueness and MySQL 5.7 compatibility.');
    }
};

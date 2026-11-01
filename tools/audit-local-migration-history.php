<?php

// Diagnostic only: SELECT/SHOW inside a server-enforced read-only transaction.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('mysql');
if ($db->getDatabaseName() !== 'spacedev_test' || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Only local spacedev_test may be inspected.');
}
$pdo = $db->getPdo();
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $identity = $db->selectOne('SELECT DATABASE() db, VERSION() version, @@hostname hostname, @@port port');
    if ($identity->db !== 'spacedev_test') {
        throw new RuntimeException('Database identity mismatch.');
    }
    $reference = json_decode(file_get_contents(__DIR__.'/../docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode(file_get_contents(__DIR__.'/../database/schema/individual-installation-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $normalize = require __DIR__.'/normalize-individual-migration-ddl.php';
    $tables = $db->select("SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME");
    $ddl = $counts = $differences = [];
    foreach ($tables as $table) {
        $name = $table->TABLE_NAME;
        $ddl[$name] = preg_replace('/ AUTO_INCREMENT=\d+/', '', ((array) $db->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $name).'`'))['Create Table']);
        $counts[$name] = $db->table($name)->count();
        if (isset($reference['ddl'][$name]) && $normalize($ddl[$name]) !== $normalize($reference['ddl'][$name])) {
            $differences[$name] = ['missing' => array_values(array_diff($normalize($reference['ddl'][$name]), $normalize($ddl[$name]))), 'extra' => array_values(array_diff($normalize($ddl[$name]), $normalize($reference['ddl'][$name])))];
        }
    }
    $history = $db->table('migrations')->orderBy('id')->get()->all();
    $triggers = $db->select('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME');
    $triggerDifferences = [];
    foreach ($reference['TRIGGERS'] as $expected) {
        $matches = array_values(array_filter($triggers, fn ($r) => $r->TRIGGER_NAME === $expected['TRIGGER_NAME']));
        foreach (['EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'ACTION_STATEMENT'] as $field) {
            if (count($matches) !== 1 || $matches[0]->$field !== $expected[$field]) {
                $triggerDifferences[] = $expected['TRIGGER_NAME'].':'.$field;
            }
        }
    }
    $files = [];
    foreach (glob(__DIR__.'/../database/migrations/*.php') as $path) {
        $file = basename($path);
        $key = 'database/migrations/'.$file;
        $source = file_get_contents($path);
        preg_match_all("/Schema::(?:create|table)\('([^']+)'/", $source, $targets);
        $files[] = ['migration' => basename($path, '.php'), 'registered' => count(array_filter($history, fn ($r) => $r->migration === basename($path, '.php'))) > 0, 'sha256' => hash_file('sha256', $path), 'matches_reviewed_manifest' => isset($manifest['files'][$key]) && hash_file('sha256', $path) === $manifest['files'][$key], 'tables' => array_values(array_unique($targets[1]))];
    }
    $orphans = [];
    $keys = $db->select('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
    $groups = [];
    foreach ($keys as $key) { $groups[$key->TABLE_NAME.'.'.$key->CONSTRAINT_NAME][] = $key; }
    $q = fn ($s) => '`'.str_replace('`', '``', $s).'`';
    foreach ($groups as $name => $parts) {
        $conditions = $nonnull = [];
        foreach ($parts as $p) {
            $conditions[] = 'p.'.$q($p->REFERENCED_COLUMN_NAME).' = c.'.$q($p->COLUMN_NAME);
            $nonnull[] = 'c.'.$q($p->COLUMN_NAME).' IS NOT NULL';
        }
        $orphans[$name] = (int) $db->selectOne('SELECT COUNT(*) n FROM '.$q($parts[0]->TABLE_NAME).' c WHERE '.implode(' AND ', $nonnull).' AND NOT EXISTS (SELECT 1 FROM '.$q($parts[0]->REFERENCED_TABLE_NAME).' p WHERE '.implode(' AND ', $conditions).')')->n;
    }
    $metadata = [];
    foreach (['COLUMNS', 'STATISTICS', 'REFERENTIAL_CONSTRAINTS'] as $view) {
        $field = $view === 'REFERENTIAL_CONSTRAINTS' ? 'CONSTRAINT_SCHEMA' : 'TABLE_SCHEMA';
        $metadata[$view] = $db->select("SELECT * FROM information_schema.$view WHERE $field=DATABASE()");
    }
    $report = ['identity' => $identity, 'tables' => $tables, 'row_counts' => $counts, 'history' => $history, 'migration_files' => $files, 'reference_missing_tables' => array_values(array_diff(array_keys($reference['ddl']), array_keys($ddl))), 'reference_ddl_differences' => $differences, 'trigger_differences' => $triggerDifferences, 'triggers' => $triggers, 'foreign_key_orphans' => $orphans, 'metadata' => $metadata, 'ddl' => $ddl];
    file_put_contents(__DIR__.'/../docs/local-migration-history-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['identity' => $identity, 'tables' => count($tables), 'registered' => $history, 'files' => count($files), 'manifest_mismatches' => array_values(array_filter($files, fn ($f) => ! $f['matches_reviewed_manifest'])), 'reference_differences' => $differences, 'trigger_differences' => $triggerDifferences, 'rows' => $counts, 'orphans' => array_filter($orphans)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} finally {
    $pdo->rollBack();
}

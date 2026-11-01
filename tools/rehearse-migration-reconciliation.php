<?php

// Stage 1 only. This program cannot reconcile spacedev_test: all mutations use
// a newly generated destination name. Credentials never enter command arguments.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = dirname(__DIR__);
$reportPath = $root.'/docs/migration-reconciliation-rehearsal.json';
$resume = in_array('--resume', $argv, true);
$prior = $resume ? json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR) : null;
$report = ['result' => 'STARTED', 'stage' => 1, 'original_modified' => false];
$save = static function () use (&$report, $reportPath): void {
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
};
set_exception_handler(static function (Throwable $e) use (&$report, $save): void {
    $report['result'] = 'FAILED';
    $report['error_class'] = get_class($e);
    if ($e instanceof \Illuminate\Database\QueryException) {
        $report['driver_code'] = $e->errorInfo[1] ?? null;
        if (isset($report['backup_directory'])) { file_put_contents($report['backup_directory'].'/failure.log', $e->getMessage()."\n", FILE_APPEND); }
    }
    // Only our RuntimeException messages are safe; query exceptions can include data.
    $report['error'] = get_class($e) === RuntimeException::class ? $e->getMessage() : 'Inspect private process logs; no credentials or row contents exported.';
    $save();
    fwrite(STDERR, $report['error']."\n");
    exit(1);
});
$config = config('database.connections.mysql');
if (! $app->environment('local') || $config['database'] !== 'spacedev_test'
    || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || ! empty($config['url']) || ! empty($config['unix_socket']) || $app->configurationIsCached()) {
    throw new RuntimeException('Uncached local loopback spacedev_test configuration required.');
}
$source = DB::connection('mysql');
$source->getPdo()->exec('START TRANSACTION READ ONLY');
$identity = $source->selectOne('SELECT DATABASE() db, VERSION() version, @@hostname hostname, @@port port');
if ($identity->db !== 'spacedev_test' || $identity->version !== '8.4.3') {
    throw new RuntimeException('Unexpected source identity; stop before backup.');
}
$report['source'] = $identity;
$normalize = require __DIR__.'/normalize-individual-migration-ddl.php';
$q = static fn ($s) => '`'.str_replace('`', '``', $s).'`';
$snapshot = static function ($connection, ?array $originalColumns = null) use ($q): array {
    $result = ['tables' => [], 'triggers' => [], 'routines' => [], 'events' => []];
    foreach ($connection->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $r) {
        $table = $r->TABLE_NAME;
        $columns = $originalColumns[$table] ?? array_map(fn ($c) => $c->COLUMN_NAME, $connection->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]));
        $hashes = [];
        foreach ($connection->table($table)->select($columns)->cursor() as $row) { $hashes[] = hash('sha256', serialize((array) $row)); }
        sort($hashes);
        $result['tables'][$table] = ['columns' => $columns, 'rows' => count($hashes), 'sha256' => hash('sha256', serialize($hashes)), 'ddl' => preg_replace('/ AUTO_INCREMENT=\d+/', '', ((array) $connection->selectOne('SHOW CREATE TABLE '.$q($table)))['Create Table'])];
    }
    $result['triggers'] = $connection->select('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME');
    $result['routines'] = $connection->select('SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION, SQL_MODE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME');
    $result['events'] = $connection->select('SELECT EVENT_NAME, EVENT_DEFINITION, STATUS, SQL_MODE, DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME');
    return $result;
};
$before = $snapshot($source);
$source->getPdo()->rollBack();
$sameSnapshot = static function (array $a, array $b) use ($normalize): bool {
    foreach ([$a, $b] as $i => $snapshot) {
        foreach ($snapshot['tables'] as &$table) { $table['ddl'] = $normalize($table['ddl']); }
        unset($table);
        $normalized[$i] = json_encode($snapshot);
    }
    return $normalized[0] === $normalized[1];
};
if ($resume && ! $sameSnapshot($before, $prior['source_before'])) { throw new RuntimeException('Source changed since the retained rehearsal.'); }
$report['source_before'] = $before;
$database = $resume ? $prior['database'] : 'spacedev_reconcile_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if (! preg_match('/^spacedev_reconcile_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/D', $database)) { throw new RuntimeException('Invalid isolated destination name.'); }
$privateDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
if (! $resume && (! mkdir($privateDir, 0700) || ! is_dir($privateDir))) { throw new RuntimeException('Cannot create private backup directory.'); }
$backup = $privateDir.DIRECTORY_SEPARATOR.'spacedev_test.sql';
$report['database'] = $database;
$report['backup_directory'] = $privateDir;
$environment = getenv();
$environment['MYSQL_PWD'] = $config['password'] ?? '';
$mysqlBin = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/';
$run = static function (array $command, ?string $input, string $log, array $env) use ($root): void {
    $descriptors = [0 => $input === null ? ['pipe', 'r'] : ['file', $input, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $process = proc_open($command, $descriptors, $pipes, $root, $env);
    if (! is_resource($process)) { throw new RuntimeException('Cannot launch isolated child process.'); }
    if ($input === null) { fclose($pipes[0]); }
    if (proc_close($process) !== 0) { throw new RuntimeException('Child process failed; inspect private log: '.$log); }
};
$client = ['--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--protocol=TCP', '--default-character-set=utf8mb4'];
$report['step'] = 'backup'; $save();
if (! $resume) {
$run([$mysqlBin.'mysqldump.exe', ...$client, '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-add-drop-table', '--skip-add-locks', '--skip-disable-keys', '--result-file='.$backup, 'spacedev_test'], null, $privateDir.'/backup.log', $environment);
} elseif (! is_file($backup) || hash_file('sha256', $backup) !== $prior['backup']['sha256']) { throw new RuntimeException('Retained backup integrity failed.'); }
if (! is_file($backup) || filesize($backup) < 1000) { throw new RuntimeException('Backup is empty or incomplete.'); }
$report['backup'] = ['file' => $backup, 'bytes' => filesize($backup), 'sha256' => hash_file('sha256', $backup), 'contains_data' => true, 'includes' => ['tables', 'rows', 'indexes', 'foreign_keys', 'triggers', 'routines', 'events'], 'database_directive' => false];
// Separate administrative connection: the source inspection connection stays read-only.
if (! $resume) {
$adminConfig = $config; $adminConfig['database'] = 'information_schema'; $adminConfig['name'] = 'rehearsal_admin';
config(['database.connections.rehearsal_admin' => $adminConfig]);
$admin = DB::connection('rehearsal_admin');
if (! preg_match('/^spacedev_reconcile_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/D', $database)
    || $admin->selectOne('SELECT COUNT(*) n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$database])->n != 0) {
    throw new RuntimeException('Destination must be a new isolated database.');
}
$admin->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$report['step'] = 'restore'; $save();
$run([$mysqlBin.'mysql.exe', ...$client, '--binary-mode', $database], $backup, $privateDir.'/restore.log', $environment);
}
unset($environment['MYSQL_PWD']);
$targetConfig = $config; $targetConfig['database'] = $database; $targetConfig['name'] = 'rehearsal';
config(['database.connections.rehearsal' => $targetConfig, 'database.default' => 'rehearsal', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
DB::setDefaultConnection('rehearsal');
Schema::clearResolvedInstance('db.schema'); $app->forgetInstance('db.schema');
$target = DB::connection('rehearsal');
if ($target->selectOne('SELECT DATABASE() db')->db !== $database || $target->getDatabaseName() === 'spacedev_test') { throw new RuntimeException('Destination guard failed.'); }
$restored = $snapshot($target);
$alreadyReconciled = $resume && isset($prior['restore']) && str_starts_with($prior['restore'], 'PASS') && $target->table('migrations')->count() > 2;
if (! $alreadyReconciled && ! $sameSnapshot($before, $restored)) { throw new RuntimeException('Restored DDL/data/triggers/routines/events differ from source snapshot.'); }
$report['restore'] = 'PASS: exact original-column row hashes and counts; equivalent normalized DDL; exact triggers, routines and events';
// MySQL SHOW CREATE after logical restore can print an explicit CHARACTER SET
// that was implicit before. Only the reviewed normalization accepts this.
$report['restore_ddl_normalization'] = 'Explicit CHARACTER SET utf8mb4 versus inherited utf8mb4 with identical utf8mb4_unicode_ci; no charset/collation change.';
echo "Backup restored and verified: $database\n";

// No history adoption before both the full inventory and every baseline object match.
$report['step'] = 'validate_baseline'; $save();
$inventory = json_decode(file_get_contents($root.'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
$baseline = json_decode(file_get_contents($root.'/database/schema/space-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($root.'/database/schema/individual-installation-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($inventory['ddl'] as $table => $ddl) {
    if (! $alreadyReconciled && (! isset($restored['tables'][$table]) || $normalize($ddl) !== $normalize($restored['tables'][$table]['ddl']))) { throw new RuntimeException('Inventory mismatch: '.$table); }
}
$validated = [];
foreach (glob($root.'/database/migrations/0000_*.php') as $path) {
    $name = basename($path, '.php');
    if (($manifest['files']['database/migrations/'.basename($path)] ?? null) !== hash_file('sha256', $path)) { throw new RuntimeException('Unreviewed base migration: '.$name); }
    $code = file_get_contents($path);
    if (str_contains($name, '_create_') && str_ends_with($name, '_table')) {
        preg_match("/Schema::create\('([^']+)'/", $code, $match); $table = $match[1];
        $missing = array_diff($normalize($baseline['tables'][$table]), $normalize($restored['tables'][$table]['ddl']));
        // The baseline deliberately omits later columns, indexes and constraints.
        if ($missing !== []) { throw new RuntimeException('Baseline table mismatch: '.$table); }
        $validated[$name] = ['sha256' => hash_file('sha256', $path), 'table' => $table, 'result' => 'PASS'];
    } elseif (str_ends_with($name, '_foreign_keys')) {
        preg_match("/Schema::table\('([^']+)'/", $code, $match); $table = $match[1];
        $n = 0;
        foreach ($baseline['foreign_keys'] as $sql) {
            if (str_starts_with($sql, "ALTER TABLE `$table` ")) {
                $line = substr($sql, strlen("ALTER TABLE `$table` ADD "));
                $want = $normalize($line)[0];
                if (! in_array($want, $normalize($restored['tables'][$table]['ddl']), true)) { throw new RuntimeException('Baseline FK mismatch: '.$table); }
                $n++;
            }
        }
        if ($n === 0) { throw new RuntimeException('No validated FKs for '.$name); }
        $validated[$name] = ['sha256' => hash_file('sha256', $path), 'table' => $table, 'foreign_keys' => $n, 'result' => 'PASS'];
    } else {
        $trigger = substr($name, strpos($name, '_create_') + 8);
        $expected = array_values(array_filter($inventory['TRIGGERS'], fn ($t) => $t['TRIGGER_NAME'] === $trigger));
        $actual = array_values(array_filter($restored['triggers'], fn ($t) => $t->TRIGGER_NAME === $trigger));
        if (count($expected) !== 1 || count($actual) !== 1) { throw new RuntimeException('Missing trigger '.$trigger); }
        foreach (['ACTION_STATEMENT', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE'] as $field) {
            if ($expected[0][$field] !== $actual[0]->$field) { throw new RuntimeException('Trigger mismatch '.$trigger); }
        }
        $validated[$name] = ['sha256' => hash_file('sha256', $path), 'trigger' => $trigger, 'result' => 'PASS'];
    }
}
if (count($validated) !== 89) { throw new RuntimeException('Expected exactly 89 validated base migrations.'); }
$report['base_validation'] = $validated;
$historyBefore = $source->table('migrations')->orderBy('id')->get()->all();
if (count($historyBefore) !== 2) { throw new RuntimeException('Expected exactly two original migration records.'); }
$baseHistoryCount = $target->table('migrations')->whereIn('migration', array_keys($validated))->count();
if ($alreadyReconciled && $baseHistoryCount !== 89) { throw new RuntimeException('Resumed rehearsal must retain all 89 previously adopted records.'); }
$target->transaction(static function () use ($target, $validated): void {
    foreach ($validated as $name => $evidence) {
        if ($target->table('migrations')->where('migration', $name)->exists()) { continue; }
        $target->table('migrations')->insert(['migration' => $name, 'batch' => 3]);
    }
});
echo "89 base migrations structurally validated and adopted in isolated copy.\n";
$report['step'] = 'migrate_isolated'; $save();
$migrationNames = [
    '0001_01_01_000000_create_users_table', '0001_01_01_000001_create_cache_table', '0001_01_01_000002_create_jobs_table',
    '2026_10_23_000001_add_organization_access_management', '2026_10_28_000001_normalize_workflow_pending_indexes',
];
foreach ($migrationNames as $name) {
    $exit = Artisan::call('migrate', ['--database' => 'rehearsal', '--path' => 'database/migrations/'.$name.'.php', '--force' => true]);
    file_put_contents($privateDir.'/migrations.log', Artisan::output(), FILE_APPEND);
    if ($exit !== 0) { throw new RuntimeException('Isolated migration failed: '.$name); }
    $report['applied'][] = $name; $save();
    echo "Applied in copy: $name\n";
}
$originalColumns = array_map(fn ($t) => $t['columns'], $before['tables']);
$after = $snapshot($target, $originalColumns);
foreach ($before['tables'] as $table => $expected) {
    if ($table !== 'migrations' && ($expected['sha256'] !== $after['tables'][$table]['sha256'] || $expected['rows'] !== $after['tables'][$table]['rows'])) { throw new RuntimeException('Existing data changed: '.$table); }
}
if ($target->table('projects')->whereNotNull('organization_id')->exists()) { throw new RuntimeException('Unexpected organization assignment.'); }
foreach (array_diff(array_keys($after['tables']), array_keys($before['tables'])) as $table) {
    if ($after['tables'][$table]['rows'] !== 0) { throw new RuntimeException('New table is not empty: '.$table); }
}
$expectedFinal = (require __DIR__.'/access-schema-expectations.php')($inventory);
foreach ($expectedFinal['ddl'] as $table => $ddl) {
    if ($table === 'migrations') { continue; }
    foreach ([['document_change_requests', 'pending_document_id', 'document_id', 'uq_dcr_pending_document'], ['technical_context_change_requests', 'pending_technical_context_id', 'technical_context_id', 'uq_tccr_pending_context']] as [$t, $column, $entity, $index]) {
        if ($table === $t) { $ddl = preg_replace('/^  UNIQUE KEY `'.$index.'` .*$/m', "  `$column` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `$entity` else NULL end)) VIRTUAL,\n  UNIQUE KEY `$index` (`$column`),", $ddl); }
    }
    if ($normalize($ddl) !== $normalize($after['tables'][$table]['ddl'])) { throw new RuntimeException('Final domain DDL mismatch: '.$table); }
}
$report['final_domain_schema'] = 'PASS';
$report['rows_after_reconciliation'] = array_map(fn ($t) => ['rows' => $t['rows'], 'sha256' => $t['sha256']], $after['tables']);
$report['history'] = $target->table('migrations')->orderBy('id')->get()->all();
if (serialize($historyBefore) !== serialize(array_slice($report['history'], 0, 2)) || count($report['history']) !== 96) { throw new RuntimeException('History preservation/count mismatch.'); }
$report['migration_status'] = [];
foreach (glob($root.'/database/migrations/*.php') as $path) {
    $name = basename($path, '.php');
    $entries = array_values(array_filter($report['history'], fn ($entry) => $entry->migration === $name));
    if (count($entries) !== 1) { throw new RuntimeException('Missing or duplicate migration history: '.$name); }
    $report['migration_status'][] = ['migration' => $name, 'batch' => $entries[0]->batch, 'registered' => true, 'sha256' => hash_file('sha256', $path)];
}
if (count($report['migration_status']) !== 96) { throw new RuntimeException('Unexpected migration file count.'); }
if (Artisan::call('migrate:status', ['--database' => 'rehearsal']) !== 0) { throw new RuntimeException('Migration status failed.'); }
file_put_contents($privateDir.'/migration-status.log', Artisan::output());
// Custom MySQL tests run with transactions and synthetic fixtures in this copy.
$environment = getenv();
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => $database, 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'RECONCILIATION_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) { $environment[$key] = $value; }
$report['step'] = 'mysql_tests'; $save();
$run([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', 'tests/Feature/MigrationReconciliationMysqlTest.php', '--log-junit', $privateDir.'/mysql-tests.xml'], null, $privateDir.'/mysql-tests.log', $environment);
$report['mysql_tests'] = ['result' => 'PASS', 'log' => $privateDir.'/mysql-tests.log', 'junit' => $privateDir.'/mysql-tests.xml'];
$testResults = simplexml_load_file($privateDir.'/mysql-tests.xml');
$report['mysql_tests']['tests'] = (int) $testResults->testsuite['tests'];
$report['mysql_tests']['assertions'] = (int) $testResults->testsuite['assertions'];
$postTests = $snapshot($target, $originalColumns);
foreach ($after['tables'] as $table => $expected) {
    if ($expected['sha256'] !== $postTests['tables'][$table]['sha256'] || $expected['rows'] !== $postTests['tables'][$table]['rows']) { throw new RuntimeException('Test modified persisted records: '.$table); }
}
$report['tables'] = count($postTests['tables']);
$report['foreign_keys'] = (int) $target->selectOne('SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()')->n;
$report['indexes'] = (int) $target->selectOne('SELECT COUNT(*) n FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()) i')->n;
$report['triggers'] = count($postTests['triggers']);
if ($report['tables'] !== 58 || $report['foreign_keys'] !== 92 || $report['indexes'] !== 187 || $report['triggers'] !== 6 || serialize($before['triggers']) !== serialize($postTests['triggers'])) { throw new RuntimeException('Final object counts or triggers mismatch.'); }
$report['foreign_key_orphans'] = [];
$groups = [];
foreach ($target->select('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION') as $key) { $groups[$key->TABLE_NAME.'.'.$key->CONSTRAINT_NAME][] = $key; }
foreach ($groups as $name => $parts) {
    $conditions = $nonnull = [];
    foreach ($parts as $p) { $conditions[] = 'p.'.$q($p->REFERENCED_COLUMN_NAME).'=c.'.$q($p->COLUMN_NAME); $nonnull[] = 'c.'.$q($p->COLUMN_NAME).' IS NOT NULL'; }
    $n = (int) $target->selectOne('SELECT COUNT(*) n FROM '.$q($parts[0]->TABLE_NAME).' c WHERE '.implode(' AND ', $nonnull).' AND NOT EXISTS (SELECT 1 FROM '.$q($parts[0]->REFERENCED_TABLE_NAME).' p WHERE '.implode(' AND ', $conditions).')')->n;
    if ($n !== 0) { throw new RuntimeException('Orphans found: '.$name); }
    $report['foreign_key_orphans'][$name] = $n;
}
// Fresh source snapshot, including history and all original DDL/data.
$source->getPdo()->exec('START TRANSACTION READ ONLY');
$sourceAfter = $snapshot($source); $source->getPdo()->rollBack();
if (! $sameSnapshot($before, $sourceAfter)) { throw new RuntimeException('Original changed concurrently; do not approve stage 2.'); }
if ($report['backup']['sha256'] !== hash_file('sha256', $backup)) { throw new RuntimeException('Backup checksum changed.'); }
$report['original_snapshot_unchanged'] = true;
$report['existing_data_preserved'] = true;
$report['test_fixtures_rolled_back'] = true;
$report['mysql57'] = 'PENDING: only MySQL 8.4.3 is installed locally; no MySQL 5.7.44 execution evidence.';
$report['result'] = 'PASS'; $report['step'] = 'awaiting_explicit_stage_2_approval'; $save();
echo "Stage 1 PASS. Original unchanged; 58 tables, 96 history records, 92 FKs, six triggers. STOP: stage 2 requires explicit approval.\n";

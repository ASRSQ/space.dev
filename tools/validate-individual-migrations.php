<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Creates a NEW randomly named database; never drops or writes the source database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});

$options = getopt('', ['existing-empty-database:', 'expected-server:', 'preflight', 'infrastructure-first']);
$existingDatabase = $options['existing-empty-database'] ?? null;
$source = DB::connection('mysql');
if ($existingDatabase !== null) {
    if (! preg_match('/^[A-Za-z0-9]+_spacedev_mig57_test(?:_[0-9]+)?$/D', $existingDatabase)
        || $source->getDatabaseName() !== $existingDatabase
        || $source->selectOne('SELECT DATABASE() db')->db !== $existingDatabase) {
        throw new RuntimeException('Destination must match DB_DATABASE and ACCOUNT_spacedev_mig57_test[_NUMBER]. Never use an existing application database.');
    }
    $database = $existingDatabase;
    if ($source->select('SHOW FULL TABLES') !== []) {
        throw new RuntimeException('Destination is not empty. Create another disposable database in cPanel; no schema changes were made.');
    }
    $expectedServer = $options['expected-server'] ?? '5.7.44';
    if (! in_array($expectedServer, ['5.7.44', '8.4.3'], true)) {
        throw new RuntimeException('Expected-server must be 5.7.44 or 8.4.3.');
    }
    $server = $source->selectOne('SELECT VERSION() v, @@innodb_page_size page_size, @@innodb_default_row_format row_format, @@log_bin log_bin, @@log_bin_trust_function_creators trust_creators');
    if (! preg_match('/^'.preg_quote($expectedServer, '/').'(?:$|[-+])/', $server->v)
        || (int) $server->page_size < 16384 || strtolower($server->row_format) !== 'dynamic') {
        throw new RuntimeException('Preflight refused: unexpected server version, page size below 16KB or default row format other than DYNAMIC.');
    }
    if ($expectedServer === '5.7.44') {
        $settings = $source->selectOne('SELECT @@innodb_large_prefix large_prefix, @@innodb_file_format file_format');
        if (! $settings->large_prefix || strtolower($settings->file_format) !== 'barracuda') {
            throw new RuntimeException('MySQL 5.7 requires innodb_large_prefix and Barracuda. No global settings were changed.');
        }
    }
    if ($server->log_bin && ! $server->trust_creators) {
        throw new RuntimeException('Trigger creation may require SUPER: log_bin is enabled and log_bin_trust_function_creators is disabled. Ask the provider to enable trigger creation; do not remove triggers.');
    }
    echo json_encode(['database' => $database, 'server' => $server->v, 'empty' => true, 'page_size' => $server->page_size, 'row_format' => $server->row_format, 'creates_database' => false], JSON_PRETTY_PRINT)."\n";
    if (isset($options['preflight'])) {
        echo "Read-only preflight passed. Database-scoped DDL/DML/TRIGGER privileges are still required for the installation test.\n";
        exit(0);
    }
} else {
    if ($source->getDatabaseName() !== 'spacedev_test') {
        throw new RuntimeException('Expected source spacedev_test. Use --existing-empty-database for a pre-created isolated hosting database.');
    }
    $database = 'spacedev_clean_validation_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $source->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$config = $source->getConfig();
$config['database'] = $database;
$config['url'] = null;
$config['name'] = 'clean_validation';
config(['database.connections.clean_validation' => $config, 'database.default' => 'clean_validation']);
DB::purge('clean_validation');
DB::setDefaultConnection('clean_validation');
Schema::clearResolvedInstance('db.schema');
$app->forgetInstance('db.schema');
$db = DB::connection('clean_validation');
if ($db->selectOne('SELECT DATABASE() AS db')->db !== $database
    || (! str_starts_with($database, 'spacedev_clean_validation_') && $existingDatabase !== $database)) {
    throw new RuntimeException('Isolated database guard failed.');
}
echo "Isolated database: $database\n";
if (in_array('--infrastructure-first', $argv, true)) {
    // Reproduce an upload that omitted the baseline but ran Laravel infrastructure.
    $status = Artisan::call('migrate', ['--database' => 'clean_validation', '--force' => true, '--path' => [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
    ]]);
    echo Artisan::output();
    if ($status !== 0) {
        throw new RuntimeException('Infrastructure-only reproduction failed.');
    }
}
$status = Artisan::call('migrate', ['--database' => 'clean_validation', '--force' => true]);
echo Artisan::output();
if ($status !== 0) {
    throw new RuntimeException('Migration failed. Isolated database retained for inspection.');
}
$inventory = json_decode(file_get_contents(dirname(__DIR__).'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
// Re-read all source DDL, rather than trusting only a previously saved report.
foreach ($inventory['ddl'] as $table => $expected) {
    if ($existingDatabase !== null) {
        break; // Hosting uses the reviewed, versioned inventory; never connects to a local source.
    }
    $live = (array) $source->selectOne("SHOW CREATE TABLE `$table`");
    if (preg_replace('/ AUTO_INCREMENT=\d+/', '', $live['Create Table']) !== $expected) {
        throw new RuntimeException("Source changed since diagnosis: $table");
    }
}
$normalize = require __DIR__.'/normalize-individual-migration-ddl.php';
$differences = [];
$expectedFinalDdl = [];
foreach ($inventory['ddl'] as $table => $expected) {
    if ($table === 'migrations') {
        continue;
    }
    foreach (['document_change_requests' => ['pending_document_id', 'document_id', 'uq_dcr_pending_document'], 'technical_context_change_requests' => ['pending_technical_context_id', 'technical_context_id', 'uq_tccr_pending_context']] as $target => [$column, $entity, $index]) {
        if ($table !== $target) {
            continue;
        }
        $expected = preg_replace('/^  UNIQUE KEY `'.preg_quote($index, '/').'` .*$/m',
            "  `$column` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `$entity` else NULL end)) VIRTUAL,\n  UNIQUE KEY `$index` (`$column`),", $expected);
    }
    $actual = (array) $db->selectOne("SHOW CREATE TABLE `$table`");
    $expectedFinalDdl[$table] = $normalize($expected);
    if ($normalize($expected) !== $normalize($actual['Create Table'])) {
        $differences[$table] = ['missing' => array_values(array_diff($normalize($expected), $normalize($actual['Create Table']))), 'extra' => array_values(array_diff($normalize($actual['Create Table']), $normalize($expected)))];
    }
}
$triggers = $db->select('SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$database]);
foreach ($inventory['TRIGGERS'] as $expected) {
    $match = array_values(array_filter($triggers, fn ($t) => $t->TRIGGER_NAME === $expected['TRIGGER_NAME']));
    if (count($match) !== 1 || $match[0]->ACTION_STATEMENT !== $expected['ACTION_STATEMENT']) {
        $differences['triggers'][] = $expected['TRIGGER_NAME'];
    }
}
$rows = 0;
foreach ($db->select('SHOW TABLES') as $row) {
    $table = array_values((array) $row)[0];
    if ($table !== 'migrations') {
        $rows += $db->table($table)->count();
    }
}
$report = ['database' => $database, 'scenario' => in_array('--infrastructure-first', $argv, true) ? 'resume_after_infrastructure_only' : 'empty_database', 'migration_history' => $db->table('migrations')->orderBy('id')->pluck('migration')->all(), 'server' => $db->selectOne('SELECT VERSION() AS v')->v, 'total_tables' => count($db->select('SHOW TABLES')), 'domain_tables_compared' => count($inventory['ddl']) - 1, 'triggers' => count($triggers), 'application_rows_after_migrate' => $rows, 'differences' => $differences];
$reportPath = dirname(__DIR__).'/docs/'.(in_array('--infrastructure-first', $argv, true) ? 'individual-infrastructure-validation.json' : 'individual-migrations-validation.json');
if ($existingDatabase !== null) {
    $reportPath = storage_path('logs/individual-hosting-'.$database.'.json');
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
if ($differences !== [] || $rows !== 0) {
    throw new RuntimeException('Schema mismatch or unexpected application rows.');
}
require __DIR__.'/clean-install-smoke.php';
require __DIR__.'/individual-integrity-smoke.php';
foreach ($expectedFinalDdl as $table => $expected) {
    $actual = (array) $db->selectOne("SHOW CREATE TABLE `$table`");
    if ($normalize($actual['Create Table']) !== $expected) {
        throw new RuntimeException("Schema changed after incremental reapplication: $table");
    }
}
$report['schema_after_incremental_reapplication'] = 'PASS';
$report['foreign_keys'] = (int) $db->selectOne('SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$database])->n;
$report['indexes'] = (int) $db->selectOne('SELECT COUNT(*) n FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?) i', [$database])->n;
$report['application_rows_after_tests'] = 0;
foreach ($db->select('SHOW TABLES') as $row) {
    $table = array_values((array) $row)[0];
    if ($table !== 'migrations') {
        $report['application_rows_after_tests'] += $db->table($table)->count();
    }
}
if ($report['application_rows_after_tests'] !== 0) {
    throw new RuntimeException('Test data leaked.');
}
$report['maximum_declared_index_bytes'] = (int) $db->selectOne("SELECT MAX(bytes) n FROM (SELECT s.TABLE_NAME, s.INDEX_NAME, SUM(CASE WHEN c.CHARACTER_MAXIMUM_LENGTH IS NOT NULL THEN COALESCE(s.SUB_PART, c.CHARACTER_MAXIMUM_LENGTH) * COALESCE(c.CHARACTER_OCTET_LENGTH / NULLIF(c.CHARACTER_MAXIMUM_LENGTH, 0), 1) WHEN c.DATA_TYPE='bigint' THEN 8 WHEN c.DATA_TYPE IN ('datetime','timestamp') THEN 8 WHEN c.DATA_TYPE='int' THEN 4 WHEN c.DATA_TYPE='smallint' THEN 2 ELSE 1 END) bytes FROM information_schema.STATISTICS s JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=s.TABLE_SCHEMA AND c.TABLE_NAME=s.TABLE_NAME AND c.COLUMN_NAME=s.COLUMN_NAME WHERE s.TABLE_SCHEMA=? GROUP BY s.TABLE_NAME,s.INDEX_NAME) sizes", [$database])->n;
$report['innodb_page_size'] = (int) $db->selectOne('SELECT @@innodb_page_size n')->n;
$report['result'] = 'PASS';
$report['mysql57'] = str_starts_with($report['server'], '5.7.44') ? 'PASS on this server; review hosting-specific privileges and settings' : 'PENDING: MySQL 5.7.44 has not been tested';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo "Final schema, index widths and empty application tables verified.\n";
echo "Report: $reportPath\n";

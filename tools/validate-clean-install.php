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

$source = DB::connection('mysql');
if ($source->getDatabaseName() !== 'spacedev_test') {
    throw new RuntimeException('Expected source spacedev_test.');
}
$database = 'spacedev_clean_validation_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$source->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
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
if ($db->selectOne('SELECT DATABASE() AS db')->db !== $database || ! str_starts_with($database, 'spacedev_clean_validation_')) {
    throw new RuntimeException('Isolated database guard failed.');
}
echo "Isolated database: $database\n";
$status = Artisan::call('migrate', ['--database' => 'clean_validation', '--force' => true]);
echo Artisan::output();
if ($status !== 0) {
    throw new RuntimeException('Migration failed. Isolated database retained for inspection.');
}
$inventory = json_decode(file_get_contents(dirname(__DIR__).'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
$normalize = static function (string $ddl): array {
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    $ddl = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', ' COLLATE utf8mb4_unicode_ci', $ddl);
    $lines = explode("\n", $ddl);
    $lines = array_map(fn ($line) => rtrim(trim($line), ','), $lines);
    sort($lines);

    return $lines;
};
$differences = [];
foreach ($inventory['ddl'] as $table => $expected) {
    if ($table === 'migrations') {
        continue;
    }
    $actual = (array) $db->selectOne("SHOW CREATE TABLE `$table`");
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
$report = ['database' => $database, 'server' => $db->selectOne('SELECT VERSION() AS v')->v, 'total_tables' => count($db->select('SHOW TABLES')), 'domain_tables_compared' => count($inventory['ddl']) - 1, 'triggers' => count($triggers), 'application_rows_after_migrate' => $rows, 'differences' => $differences];
file_put_contents(dirname(__DIR__).'/docs/clean-install-validation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
if ($differences !== [] || $rows !== 0) {
    throw new RuntimeException('Schema mismatch or unexpected application rows.');
}
require __DIR__.'/clean-install-smoke.php';

<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only inventory. Never exports rows or connection credentials.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('mysql');
if ($db->getDatabaseName() !== 'spacedev_test') {
    throw new RuntimeException('Expected read-only source spacedev_test.');
}
$out = ['server' => $db->selectOne('SELECT VERSION() AS version')->version];
foreach (['TABLES', 'COLUMNS', 'STATISTICS', 'TABLE_CONSTRAINTS', 'KEY_COLUMN_USAGE', 'REFERENTIAL_CONSTRAINTS', 'CHECK_CONSTRAINTS', 'TRIGGERS'] as $view) {
    $field = in_array($view, ['TABLE_CONSTRAINTS', 'REFERENTIAL_CONSTRAINTS', 'CHECK_CONSTRAINTS']) ? 'CONSTRAINT_SCHEMA' : ($view === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : 'TABLE_SCHEMA');
    $rows = $db->select("SELECT * FROM information_schema.$view WHERE $field = ?", ['spacedev_test']);
    if ($view === 'TABLES') {
        $rows = array_map(fn ($r) => (object) array_intersect_key((array) $r, array_flip(['TABLE_NAME', 'ENGINE', 'TABLE_COLLATION', 'TABLE_TYPE'])), $rows);
    }
    $out[$view] = $rows;
}
$out['ddl'] = [];
foreach ($out['TABLES'] as $table) {
    $name = $table->TABLE_NAME;
    $ddl = (array) $db->selectOne("SHOW CREATE TABLE `$name`");
    $out['ddl'][$name] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl['Create Table']);
}
$out['migrations'] = $db->table('migrations')->orderBy('id')->get()->all();
if (in_array('--verify', $argv, true)) {
    $expected = json_decode(file_get_contents(dirname(__DIR__).'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
    $current = json_decode(json_encode($out), true, flags: JSON_THROW_ON_ERROR);
    // information_schema has no guaranteed row order. Compare trigger metadata
    // by identity, including bodies, timestamps, definers and SQL modes.
    usort($current['TRIGGERS'], fn ($a, $b) => $a['TRIGGER_NAME'] <=> $b['TRIGGER_NAME']);
    usort($expected['TRIGGERS'], fn ($a, $b) => $a['TRIGGER_NAME'] <=> $b['TRIGGER_NAME']);
    ksort($current['ddl']);
    ksort($expected['ddl']);
    foreach (['ddl', 'TRIGGERS', 'migrations'] as $key) {
        if ($current[$key] !== $expected[$key]) {
            fwrite(STDERR, "Source metadata changed: $key\n");
            exit(1);
        }
    }
    echo "Source DDL, triggers and migration history unchanged.\n";
    exit(0);
}
file_put_contents(dirname(__DIR__).'/docs/schema-inventory.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['server' => $out['server'], 'tables' => array_keys($out['ddl']), 'triggers' => count($out['TRIGGERS']), 'migrations' => $out['migrations']], JSON_PRETTY_PRINT);

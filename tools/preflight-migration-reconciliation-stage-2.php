<?php

// Stage 2 preflight ONLY. No migrations, inserts, seeds or recovery actions.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = dirname(__DIR__);
$expected = json_decode(file_get_contents($root.'/docs/migration-reconciliation-rehearsal.json'), true, flags: JSON_THROW_ON_ERROR);
$out = ['stage' => 2, 'mode' => 'read_only_preflight', 'database_modified' => false, 'failures' => []];
$config = config('database.connections.mysql');
if (! $app->environment('local') || $app->configurationIsCached() || $config['database'] !== 'spacedev_test'
    || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || ! empty($config['url']) || ! empty($config['unix_socket'])) {
    throw new RuntimeException('Uncached local loopback spacedev_test configuration required.');
}
$db = DB::connection('mysql');
$db->getPdo()->exec('START TRANSACTION READ ONLY');
try {
    $identity = (array) $db->selectOne('SELECT DATABASE() db, VERSION() version, @@hostname hostname, @@port port');
    $out['identity'] = $identity;
    if ($identity !== $expected['source']) { $out['failures'][] = 'Database/server identity differs from stage 1.'; }
    $normalize = require __DIR__.'/normalize-individual-migration-ddl.php';
    $inventory = json_decode(file_get_contents($root.'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
    $out['reference_encoding_diagnostics'] = ['inventory_ddl_matches' => [], 'stage_1_encoding_only_differences' => [], 'trigger_differences' => []];
    $tables = array_map(fn ($r) => $r->TABLE_NAME, $db->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"));
    $out['tables'] = count($tables);
    if ($tables !== array_keys($expected['source_before']['tables'])) { $out['failures'][] = 'Table set differs from stage 1.'; }
    $out['table_checks'] = [];
    $total = 0;
    foreach ($expected['source_before']['tables'] as $table => $original) {
        if (! in_array($table, $tables, true)) { continue; }
        $quoted = '`'.str_replace('`', '``', $table).'`';
        $ddl = ((array) $db->selectOne('SHOW CREATE TABLE '.$quoted))['Create Table'];
        $hashes = [];
        foreach ($db->table($table)->select($original['columns'])->cursor() as $record) { $hashes[] = hash('sha256', serialize((array) $record)); }
        sort($hashes);
        $columns = array_map(fn ($c) => $c->COLUMN_NAME, $db->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]));
        $check = ['rows' => count($hashes), 'sha256' => hash('sha256', serialize($hashes)), 'rows_match' => count($hashes) === $original['rows'] && hash('sha256', serialize($hashes)) === $original['sha256'], 'ddl_match' => $normalize($ddl) === $normalize($original['ddl']), 'columns_match' => $columns === $original['columns']];
        $out['table_checks'][$table] = $check;
        $out['reference_encoding_diagnostics']['inventory_ddl_matches'][$table] = isset($inventory['ddl'][$table]) && $normalize($ddl) === $normalize($inventory['ddl'][$table]);
        if (! $check['ddl_match']) {
            // Diagnostic comparison only: never rewrite or silently repair evidence.
            $decoded = mb_convert_encoding($original['ddl'], 'Windows-1252', 'UTF-8');
            $out['reference_encoding_diagnostics']['stage_1_encoding_only_differences'][$table] = $normalize($ddl) === $normalize($decoded);
        }
        if (! $check['rows_match'] || ! $check['ddl_match'] || ! $check['columns_match']) { $out['failures'][] = 'Schema/data divergence: '.$table; }
        if ($table !== 'migrations') { $total += count($hashes); }
    }
    $out['business_rows'] = $total;
    $out['history'] = $db->table('migrations')->orderBy('id')->get()->all();
    foreach ([
        'triggers' => 'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME',
        'routines' => 'SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION, SQL_MODE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME',
        'events' => 'SELECT EVENT_NAME, EVENT_DEFINITION, STATUS, SQL_MODE, DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME',
    ] as $key => $sql) {
        $actual = json_decode(json_encode($db->select($sql)), true);
        $out[$key.'_match'] = $actual === $expected['source_before'][$key];
        if ($key === 'triggers' && ! $out[$key.'_match']) {
            foreach ($actual as $i => $trigger) {
                foreach ($trigger as $field => $value) {
                    $recorded = $expected['source_before'][$key][$i][$field] ?? null;
                    if ($value !== $recorded) {
                        $out['reference_encoding_diagnostics']['trigger_differences'][] = ['trigger' => $trigger['TRIGGER_NAME'], 'field' => $field, 'encoding_only' => is_string($recorded) && $value === mb_convert_encoding($recorded, 'Windows-1252', 'UTF-8')];
                    }
                }
            }
        }
        if (! $out[$key.'_match']) { $out['failures'][] = 'Metadata divergence: '.$key; }
    }
    $out['code_checks'] = [];
    foreach ($expected['migration_status'] as $file) {
        $path = 'database/migrations/'.$file['migration'].'.php';
        $out['code_checks'][$path] = is_file($root.'/'.$path) && hash_file('sha256', $root.'/'.$path) === $file['sha256'];
    }
    foreach ($expected['artifact_sha256'] as $path => $hash) { $out['code_checks'][$path] = is_file($root.'/'.$path) && hash_file('sha256', $root.'/'.$path) === $hash; }
    foreach ($out['code_checks'] as $path => $matches) { if (! $matches) { $out['failures'][] = 'Code differs from rehearsal: '.$path; } }
    if (count(glob($root.'/database/migrations/*.php')) !== 96) { $out['failures'][] = 'Migration file set differs from rehearsal.'; }
    $backup = $expected['backup']['durable_file'];
    $out['backup'] = ['file' => $backup, 'exists' => is_file($backup), 'checksum_match' => is_file($backup) && hash_file('sha256', $backup) === $expected['backup']['sha256'], 'bytes_match' => is_file($backup) && filesize($backup) === $expected['backup']['bytes']];
    if (! $out['backup']['checksum_match'] || ! $out['backup']['bytes_match']) { $out['failures'][] = 'Durable backup integrity failed.'; }
    $savedReportPath = dirname($backup).'/rehearsal-report.json';
    $savedReport = is_file($savedReportPath) ? json_decode(file_get_contents($savedReportPath), true, flags: JSON_THROW_ON_ERROR) : null;
    $out['backup']['restore_evidence_match'] = $savedReport !== null && $savedReport['result'] === 'PASS'
        && $savedReport['source_before'] === $expected['source_before'] && $savedReport['backup']['sha256'] === $expected['backup']['sha256'] && str_starts_with($savedReport['restore'], 'PASS');
    if (! $out['backup']['restore_evidence_match']) { $out['failures'][] = 'Durable restoration evidence missing or divergent.'; }
    // User-requested identities/roles, without passwords, tokens or emails.
    $out['requested_users'] = [];
    foreach (['Alex', 'Lauro'] as $name) {
        $matches = $db->table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->where('u.name', 'like', '%'.$name.'%')->get(['u.id', 'u.name', 'u.status', 'r.slug as global_role'])->all();
        $out['requested_users'][$name] = $matches;
    }
    $out['result'] = $out['failures'] === [] ? 'PASS' : 'STOPPED';
} finally {
    $db->getPdo()->rollBack();
}
file_put_contents($root.'/docs/migration-reconciliation-stage-2-preflight.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['result' => $out['result'], 'identity' => $out['identity'], 'tables' => $out['tables'], 'business_rows' => $out['business_rows'], 'registered_migrations' => count($out['history']), 'backup' => $out['backup'], 'code_files_verified' => count($out['code_checks']), 'requested_users' => $out['requested_users'], 'encoding_only_table_differences' => count(array_filter($out['reference_encoding_diagnostics']['stage_1_encoding_only_differences'])), 'all_46_ddls_match_original_inventory' => count(array_filter($out['reference_encoding_diagnostics']['inventory_ddl_matches'])) === 46, 'trigger_diagnostics' => $out['reference_encoding_diagnostics']['trigger_differences'], 'failures' => $out['failures']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($out['result'] === 'PASS' ? 0 : 1);

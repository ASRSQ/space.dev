<?php

// Local-only integration runner. Writes exclusively to a NEW randomly named database.
// No DROP, rollback migrations, source writes, seeds, or production operations.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $error): void {
    // Avoid dumping PDO credentials or environment values on failures.
    $driverCode = $error instanceof QueryException ? ($error->errorInfo[1] ?? 'unknown') : 'n/a';
    fwrite(STDERR, 'Local validation failed ('.get_class($error).', driver code '.$driverCode.'). Inspect the isolated database and local logs.'.PHP_EOL);
    exit(1);
});

$config = config('database.connections.mysql');
if (! $app->environment('local') || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || ! empty($config['url']) || ! empty($config['unix_socket'])) {
    throw new RuntimeException('Requires local environment and explicit loopback MySQL connection.');
}
$source = DB::connection('mysql');
$server = $source->selectOne('SELECT VERSION() AS version, @@port AS port');
if (str_contains(strtolower($server->version), 'mariadb')) {
    throw new RuntimeException('MySQL required.');
}

// Read-only evidence for the actual local task/user. Never assign or update them.
$snapshot = static function () use ($source): string {
    return hash('sha256', json_encode([
        $source->table('tasks')->orderBy('id')->get()->all(),
        $source->table('users')->orderBy('id')->get()->all(),
        $source->table('project_memberships')->orderBy('id')->get()->all(),
        $source->table('problems')->orderBy('id')->get()->all(),
    ], JSON_THROW_ON_ERROR));
};
$before = $snapshot();
$actualTasks = $source->table('tasks')->where('title', 'like', '%SC-001%')->get(['id', 'project_id', 'assigned_user_id']);
$lauros = $source->table('users')->where('name', 'like', '%Lauro%')->get(['id', 'status']);
$eligibility = [];
foreach ($actualTasks as $task) {
    foreach ($lauros as $lauro) {
        $project = $source->table('projects')->find($task->project_id);
        $eligibility[] = [
            'task_id' => $task->id, 'lauro_id' => $lauro->id,
            'currently_assigned' => $task->assigned_user_id === $lauro->id,
            'eligible' => $lauro->status === 'active' && $source->table('project_memberships')->where('project_id', $task->project_id)->where('user_id', $lauro->id)->where('status', 'active')->exists(),
            'project_editable' => $project && ! $project->is_archived && $project->archived_at === null && $project->status !== 'archived',
        ];
    }
}

$database = 'spacedev_editor_validation_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
$source->statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config['database'] = $database;
$config['url'] = null;
config(['database.connections.editor_validation' => $config, 'database.default' => 'editor_validation']);
DB::setDefaultConnection('editor_validation');
$target = DB::connection('editor_validation');
if ($target->selectOne('SELECT DATABASE() AS db')->db !== $database) {
    throw new RuntimeException('Database guard failed.');
}
echo "New isolated MySQL database: $database\n";
$status = Artisan::call('migrate', ['--database' => 'editor_validation', '--force' => true]);
if ($status !== 0) {
    throw new RuntimeException('Isolated installation failed.');
}

// Explicit environment for the child: no credential arguments, no .env changes.
$environment = getenv();
foreach ([
    'EDITOR_MYSQL_VALIDATION' => '1', 'EDITOR_MYSQL_DATABASE' => $database,
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
    'DB_DATABASE' => $database, 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    $environment[$key] = $value;
}
$process = proc_open([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', 'tests/Feature/MySqlEditorValidationTest.php', '--log-junit', 'storage/app/private/editor-mysql-results.xml'], [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__), $environment);
if (! is_resource($process)) {
    throw new RuntimeException('Could not start PHPUnit.');
}
$exitCode = proc_close($process);
$remainingRows = 0;
foreach ($target->select('SHOW TABLES') as $row) {
    $table = array_values((array) $row)[0];
    if ($table !== 'migrations') {
        $remainingRows += $target->table($table)->count();
    }
}
$report = [
    'database' => $database, 'server' => $server->version, 'port' => $server->port,
    'php' => PHP_VERSION, 'phpunit_exit_code' => $exitCode,
    'source_rows_unchanged' => hash_equals($before, $snapshot()),
    'application_rows_after_tests' => $remainingRows,
    'actual_local_sc001_matches' => $actualTasks->count(), 'actual_local_lauro_matches' => $lauros->count(),
    'actual_local_assignment_read_only' => $eligibility,
    'browser_executed' => false,
];
file_put_contents(dirname(__DIR__).'/docs/editor-mysql-validation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($exitCode === 0 && $report['source_rows_unchanged'] && $remainingRows === 0 ? 0 : 1);

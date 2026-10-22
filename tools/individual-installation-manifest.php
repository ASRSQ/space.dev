<?php

// Offline package integrity only. Never connects to a database or reads .env.
$root = dirname(__DIR__);
$manifestPath = $root.'/database/schema/individual-installation-manifest.json';
$migrations = array_map('basename', glob($root.'/database/migrations/*.php'));
sort($migrations);
$paths = array_map(fn ($name) => 'database/migrations/'.$name, $migrations);
$paths = array_merge($paths, ['app/Support/DomainMigrationPlatform.php', 'app/Support/ProjectDocumentationMigrationSafety.php', 'docs/individual-migrations.md', 'docs/mysql57-final-review.md', 'docs/schema-inventory.json', 'tools/validate-individual-migrations.php', 'tools/clean-install-smoke.php', 'tools/individual-integrity-smoke.php']);
$manifest = ['migration_order' => $migrations, 'files' => []];
foreach ($paths as $path) {
    $manifest['files'][$path] = hash_file('sha256', $root.'/'.$path);
}
if (in_array('--write', $argv, true)) {
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo "Individual migration manifest written.\n";
} else {
    $expected = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if ($manifest !== $expected) {
        fwrite(STDERR, "Package differs from individual migration manifest. Review changes before regenerating.\n");
        exit(1);
    }
    echo count($migrations)." migration files and dependencies verified. This does not validate an existing database.\n";
}

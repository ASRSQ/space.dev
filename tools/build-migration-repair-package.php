<?php

// Local packaging only: no credentials, .env, vendor, database rows or SQL dumps.
$root = dirname(__DIR__);
$migrations = glob($root.'/database/migrations/*.php');
sort($migrations, SORT_STRING);
$files = array_map(fn ($path) => 'database/migrations/'.basename($path), $migrations);
$files = array_merge($files, [
    'database/schema/space-baseline.json',
    'app/Support/ProjectDocumentationMigrationSafety.php',
    'app/Console/Commands/CreateFirstAdministrator.php',
    'database/seeders/DatabaseSeeder.php',
    'database/seeders/RoleSeeder.php',
    'tools/check-installation-package.php',
]);
$manifest = ['migration_order' => array_map('basename', $migrations), 'files' => []];
foreach ($files as $path) {
    $manifest['files'][$path] = hash_file('sha256', $root.'/'.$path);
}
file_put_contents($root.'/database/schema/installation-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
$zip = new ZipArchive;
@mkdir($root.'/dist', 0777, true);
$target = $root.'/dist/spacedev-migration-repair.zip';
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create repair archive.');
}
foreach (array_merge($files, ['database/schema/installation-manifest.json', 'docs/hostgator-migration-repair.md', 'docs/shared-hosting-installation.md']) as $path) {
    if (! $zip->addFile($root.'/'.$path, $path)) {
        throw new RuntimeException('Cannot package '.$path);
    }
}
$zip->close();
echo $target."\nSHA-256: ".hash_file('sha256', $target)."\n";

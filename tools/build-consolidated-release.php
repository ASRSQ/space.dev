<?php

// Local packaging only. No Laravel bootstrap, database or publication.
$root = dirname(__DIR__);
$files = [
    'database/migrations/2026_10_23_000001_add_organization_access_management.php',
    'docs/consolidated-prepublication-review.md', 'docs/consolidated-installation.md',
    'docs/consolidated-mysql-validation.json', 'tools/check-consolidated-release.php',
    'public/build/manifest.json',
];
foreach (['app', 'resources/views'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
foreach (json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR) as $asset) {
    foreach (array_merge([$asset['file']], $asset['css'] ?? [], $asset['assets'] ?? []) as $file) {
        $files[] = 'public/build/'.$file;
    }
}
$files = array_values(array_unique($files));
sort($files);
$manifest = [];
foreach ($files as $file) {
    if (! is_file($root.'/'.$file)) {
        throw new RuntimeException('Missing release file: '.$file);
    }
    $manifest[$file] = hash_file('sha256', $root.'/'.$file);
}
$metadata = [
    'status' => 'Ready for isolated acceptance testing; NOT production approved',
    'php_tested' => PHP_VERSION, 'mysql_tested' => '8.4.3', 'mysql_57_execution_pending' => true,
    'composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
    'package_lock_sha256' => hash_file('sha256', $root.'/package-lock.json'),
    'migration' => '2026_10_23_000001_add_organization_access_management',
    'database_seeds' => false, 'files' => $manifest,
];
if (! is_dir($root.'/dist')) {
    mkdir($root.'/dist', 0777, true);
}
$target = $root.'/dist/spacedev-consolidated.zip';
$zip = new ZipArchive;
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create consolidated package');
}
foreach ($files as $file) {
    $zip->addFile($root.'/'.$file, $file);
}
$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$zip->addFromString('consolidated-release-manifest.json', $json);
$zip->close();
file_put_contents($root.'/dist/consolidated-release-manifest.json', $json);
file_put_contents($target.'.sha256', hash_file('sha256', $target).'  spacedev-consolidated.zip'."\n");
echo count($files)." files; SHA-256 ".hash_file('sha256', $target)."\n";

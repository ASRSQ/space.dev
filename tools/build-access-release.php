<?php

// Local packaging only: application code + one additive migration; no credentials or data.
$root = dirname(__DIR__);
$files = [
    'database/migrations/2026_10_23_000001_add_organization_access_management.php',
    'docs/access-management.md',
    'docs/access-files.json',
    'docs/access-mysql-validation.json',
    'tools/check-access-release.php',
    'public/build/manifest.json',
];
foreach (['app', 'resources/views'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
$assets = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($assets as $asset) {
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
if (! is_dir($root.'/dist')) {
    mkdir($root.'/dist', 0777, true);
}
$zip = new ZipArchive;
$target = $root.'/dist/spacedev-access.zip';
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create access release.');
}
foreach ($files as $file) {
    $zip->addFile($root.'/'.$file, $file);
}
$zip->addFromString('access-release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
$zip->close();
file_put_contents($target.'.sha256', hash_file('sha256', $target).'  spacedev-access.zip'."\n");
echo $target."\nFiles: ".count($files)."\nSHA-256: ".hash_file('sha256', $target)."\n";

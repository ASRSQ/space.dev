<?php

// Standalone read-only verification; optional installed vendor dependency check.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/consolidated-release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $file => $expected) {
    if (! preg_match('~^[a-zA-Z0-9_./-]+$~D', $file) || str_contains($file, '..') || str_starts_with($file, '/')
        || ! is_file($root.'/'.$file) || ! hash_equals($expected, hash_file('sha256', $root.'/'.$file))) {
        fwrite(STDERR, 'Release integrity failed: '.$file."\n");
        exit(1);
    }
}
if (in_array('--dependencies', $argv, true)) {
    if (! is_file($root.'/composer.lock') || ! hash_equals($manifest['composer_lock_sha256'], hash_file('sha256', $root.'/composer.lock'))) {
        fwrite(STDERR, "Installed composer.lock differs; stop and reconcile dependencies.\n");
        exit(1);
    }
    require $root.'/vendor/autoload.php';
}
echo 'Consolidated integrity OK ('.count($manifest['files'])." files).\n";

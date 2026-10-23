<?php

// Integrity check only; does not bootstrap Laravel, access .env, or connect to a database.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/editor-release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest as $file => $expected) {
    if (str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, ':') || ! is_file($root.'/'.$file) || ! hash_equals($expected, hash_file('sha256', $root.'/'.$file))) {
        fwrite(STDERR, 'Release integrity failed: '.$file."\n");
        exit(1);
    }
}
echo 'Editor release integrity OK ('.count($manifest)." files).\n";

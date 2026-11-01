<?php

// Standalone transfer integrity check: no Laravel bootstrap, .env, database, or writes.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/access-release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest as $file => $expected) {
    if (str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, ':') || ! is_file($root.'/'.$file) || ! hash_equals($expected, hash_file('sha256', $root.'/'.$file))) {
        fwrite(STDERR, 'Access release integrity failed: '.$file."\n");
        exit(1);
    }
}
echo 'Access release integrity OK ('.count($manifest)." files).\n";

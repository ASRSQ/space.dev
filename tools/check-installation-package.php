<?php

// Standalone, read-only: works before Composer/Artisan and never reads .env.
$root = dirname(__DIR__);
$manifestPath = $root.'/database/schema/installation-manifest.json';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Missing database/schema/installation-manifest.json\n");
    exit(1);
}
$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$errors = [];
foreach ($manifest['files'] as $path => $hash) {
    if (! is_file($root.'/'.$path)) {
        $errors[] = 'Missing: '.$path;
    } elseif (! hash_equals($hash, hash_file('sha256', $root.'/'.$path))) {
        $errors[] = 'Different content: '.$path;
    }
}
$actual = array_map('basename', glob($root.'/database/migrations/*.php'));
sort($actual, SORT_STRING);
if ($actual !== $manifest['migration_order']) {
    $errors[] = 'Migration names/order differ from the validated package (review extra or missing files).';
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
echo "Package files verified (SHA-256). Migration order:\n";
foreach ($actual as $index => $name) {
    echo ($index + 1).'. '.$name."\n";
}
echo "This checks files only; it does not certify the remote database state.\n";

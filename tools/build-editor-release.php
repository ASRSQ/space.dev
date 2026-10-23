<?php

// Explicit allowlist: never package .env, migrations, databases, logs, or unrelated changes.
$root = dirname(__DIR__);
$files = [
    'app/Filament/Concerns/InteractsWithDescriptionChecklist.php',
    'app/Filament/Concerns/ProtectsEditorFromStaleUpdates.php',
    'app/Filament/Resources/Tasks/TaskResource.php',
    'app/Filament/Resources/Tasks/Pages/EditTask.php',
    'app/Filament/Resources/Tasks/Pages/ViewTask.php',
    'app/Filament/Resources/Tasks/Pages/TaskKanban.php',
    'app/Filament/Resources/Problems/ProblemResource.php',
    'app/Filament/Resources/Problems/Pages/ListProblems.php',
    'app/Filament/Resources/Problems/Pages/CreateProblem.php',
    'app/Filament/Resources/Problems/Pages/EditProblem.php',
    'app/Filament/Resources/Problems/Pages/ViewProblem.php',
    'app/Policies/ProblemPolicy.php',
    'app/Providers/AppServiceProvider.php',
    'app/Services/TaskAssignmentService.php',
    'app/Support/TaskMarkdown.php',
    'resources/views/filament/tasks/description.blade.php',
    'resources/views/filament/tasks/description-preview.blade.php',
    'package-lock.json',
    'docs/editor-prepublication-review.md',
    'docs/editor-mysql-validation.json',
    'tools/check-editor-release.php',
    'public/build/manifest.json',
];
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
$zip = new ZipArchive;
if (! is_dir($root.'/dist')) {
    mkdir($root.'/dist', 0777, true);
}
$target = $root.'/dist/spacedev-editors.zip';
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create editor release.');
}
foreach ($files as $file) {
    $zip->addFile($root.'/'.$file, $file);
}
$zip->addFromString('editor-release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
$zip->close();
file_put_contents($target.'.sha256', hash_file('sha256', $target).'  spacedev-editors.zip'."\n");
echo $target."\nFiles: ".count($files)."\nSHA-256: ".hash_file('sha256', $target)."\n";

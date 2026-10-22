<?php

// Included only after validate-individual-migrations.php's destination guard.
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

if ($db->getDatabaseName() !== $database || $database === 'spacedev_test'
    || (! str_starts_with($database, 'spacedev_clean_validation_') && ! preg_match('/^[A-Za-z0-9]+_spacedev_mig57_test(?:_[0-9]+)?$/D', $database))) {
    throw new RuntimeException('Disposable target required.');
}
$checks = [];
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$rejectExact = static function (callable $operation, int $code, string $label) use ($assert): void {
    try {
        $operation();
    } catch (QueryException $e) {
        $assert((int) ($e->errorInfo[1] ?? 0) === $code, "$label: expected MySQL error $code; got ".$e->getMessage());

        return;
    }
    throw new RuntimeException('Accepted invalid data: '.$label);
};
$assert((int) $db->selectOne('SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$database])->n === 83, 'Exactly 83 final foreign keys');
$assert((int) $db->selectOne('SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$database])->n === 6, 'Exactly six final triggers');
$assert((int) $db->selectOne('SELECT COUNT(*) n FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?) i', [$database])->n === 172, '172 indexes including seven infrastructure tables');
$db->beginTransaction();
try {
    $role = $db->table('roles')->insertGetId(['name' => 'Integrity', 'slug' => 'integrity']);
    $user = $db->table('users')->insertGetId(['name' => 'Integrity', 'email' => 'integrity@example.test', 'password_hash' => 'unused', 'role_id' => $role]);
    $project = $db->table('projects')->insertGetId(['name' => 'Integrity', 'slug' => 'integrity', 'owner_user_id' => $user]);
    $other = $db->table('projects')->insertGetId(['name' => 'Other', 'slug' => 'integrity-other', 'owner_user_id' => $user]);
    $doc = $db->table('documents')->insertGetId(['project_id' => $project, 'title' => 'Doc', 'slug' => 'doc']);
    $ctx = $db->table('technical_contexts')->insertGetId(['project_id' => $project, 'title' => 'Context', 'slug' => 'context']);
    foreach ([['document_change_requests', 'document_id', $doc, ['proposed_title' => 'Title', 'proposed_content' => 'Content']], ['technical_context_change_requests', 'technical_context_id', $ctx, ['proposed_architecture_summary' => 'Architecture']]] as [$table,$key,$entity,$payload]) {
        $row = $payload + [$key => $entity, 'proposed_version_number' => 1, 'reason' => 'Test', 'author_user_id' => $user];
        $pending = $db->table($table)->insertGetId($row);
        $rejectExact(fn () => $db->table($table)->insert($row), 1062, "$table rejects second pending insert");
        $approved = $db->table($table)->insertGetId($row + ['status' => 'approved']);
        $db->table($table)->insert($row + ['status' => 'rejected']);
        $rejectExact(fn () => $db->table($table)->where('id', $approved)->update(['status' => 'pending']), 1062, "$table rejects pending transition");
        $db->table($table)->where('id', $pending)->update(['status' => 'approved']);
        $db->table($table)->where('id', $approved)->update(['status' => 'pending']);
        $assert($db->table($table)->where('status', 'approved')->count() === 1, "$table releases pending key and retains history");
    }
    $member = ['project_id' => $project, 'user_id' => $user, 'granted_by_user_id' => $user];
    $id = $db->table('project_memberships')->insertGetId($member);
    $rejectExact(fn () => $db->table('project_memberships')->insert($member), 1062, 'Duplicate active membership rejected');
    $db->table('project_memberships')->insert($member + ['status' => 'revoked']);
    $db->table('project_memberships')->insert($member + ['status' => 'revoked']);
    $db->table('project_memberships')->where('id', $id)->update(['status' => 'revoked']);
    $db->table('project_memberships')->insert($member);
    $assert($db->table('project_memberships')->where('status', 'revoked')->count() === 3, 'Membership revocation releases unique key');
    $newProject = $project + 1000000;
    $db->table('projects')->where('id', $project)->update(['id' => $newProject]);
    $assert($db->table('project_memberships')->where('active_user_project_key', $newProject.':'.$user)->count() === 1, 'Generated membership unique index follows ON UPDATE CASCADE');
    $db->table('projects')->where('id', $newProject)->update(['id' => $project]);
    foreach ([['documents', 'document_change_requests', 'document_id', 'pending_document_id', $doc], ['technical_contexts', 'technical_context_change_requests', 'technical_context_id', 'pending_technical_context_id', $ctx]] as [$parentTable, $requests, $foreignColumn, $generatedColumn, $parentId]) {
        $newId = $parentId + 1000000;
        $db->table($parentTable)->where('id', $parentId)->update(['id' => $newId]);
        $assert($db->table($requests)->where($generatedColumn, $newId)->count() === 1, "$requests generated unique index follows ON UPDATE CASCADE");
        $db->table($parentTable)->where('id', $newId)->update(['id' => $parentId]);
    }
    $problem = $db->table('problems')->insertGetId(['project_id' => $project, 'title' => 'Problem', 'description' => 'Test', 'reporter_user_id' => $user]);
    $pairs = [
        ['documents', 'document_versions', 'document_id', ['project_id' => $project, 'title' => 'Other doc', 'slug' => 'other-doc'], ['title' => 'Version', 'content_markdown' => 'Content']],
        ['technical_contexts', 'technical_context_versions', 'technical_context_id', ['project_id' => $project, 'title' => 'Other ctx', 'slug' => 'other-ctx'], ['architecture_summary' => 'Version']],
        ['prompt_templates', 'prompt_versions', 'prompt_template_id', ['project_id' => $project, 'name' => 'Prompt', 'slug' => 'prompt', 'created_by_user_id' => $user], ['user_prompt_template' => 'Version']],
        ['solutions', 'solution_versions', 'solution_id', ['problem_id' => $problem, 'title' => 'Solution', 'author_user_id' => $user], ['solution_steps_markdown' => 'Version']],
    ];
    foreach ($pairs as [$table,$versions,$fk,$parent,$version]) {
        $a = $db->table($table)->insertGetId($parent);
        if (isset($parent['slug'])) {
            $parent['slug'] .= '-b';
        }
        $b = $db->table($table)->insertGetId($parent);
        $v = $db->table($versions)->insertGetId($version + [$fk => $a, 'version_number' => 1, 'created_by_user_id' => $user]);
        $rejectExact(fn () => $db->table($table)->where('id', $b)->update(['current_version_id' => $v]), 1452, "$table rejects version belonging to another parent");
        $db->table($table)->where('id', $a)->update(['current_version_id' => $v]);
        $rejectExact(fn () => $db->table($versions)->where('id', $v)->delete(), 1451, "$table protects current version deletion");
        $db->table($table)->where('id', $a)->update(['current_version_id' => null]);
        $db->table($table)->where('id', $a)->delete();
        $assert(! $db->table($versions)->where('id', $v)->exists(), "$table cascades historical versions after clearing pointer");
    }
    $foreignCategory = $db->table('categories')->insertGetId(['project_id' => $other, 'name' => 'Foreign', 'slug' => 'foreign']);
    $foreignTag = $db->table('tags')->insertGetId(['project_id' => $other, 'name' => 'Foreign', 'slug' => 'foreign']);
    $localTag = $db->table('tags')->insertGetId(['project_id' => $project, 'name' => 'Local', 'slug' => 'local']);
    $rejectExact(fn () => $db->table('documents')->insert(['project_id' => $project, 'title' => 'Bad', 'slug' => 'bad', 'category_id' => $foreignCategory]), 1644, 'Category INSERT trigger');
    $rejectExact(fn () => $db->table('documents')->where('id', $doc)->update(['category_id' => $foreignCategory]), 1644, 'Category UPDATE trigger');
    $rejectExact(fn () => $db->table('document_tags')->insert(['document_id' => $doc, 'tag_id' => $foreignTag]), 1644, 'Tag INSERT trigger');
    $db->table('document_tags')->insert(['document_id' => $doc, 'tag_id' => $localTag]);
    $rejectExact(fn () => $db->table('document_tags')->where('document_id', $doc)->update(['tag_id' => $foreignTag]), 1644, 'Tag UPDATE trigger');
    $task = ['project_id' => $project, 'title' => 'Task', 'reporter_user_id' => $user];
    $a = $db->table('tasks')->insertGetId($task);
    $b = $db->table('tasks')->insertGetId($task);
    $rejectExact(fn () => $db->table('task_relations')->insert(['source_task_id' => $a, 'target_task_id' => $a, 'relation_type' => 'blocks']), 1644, 'Task relation INSERT trigger');
    $db->table('task_relations')->insert(['source_task_id' => $a, 'target_task_id' => $b, 'relation_type' => 'blocks']);
    $rejectExact(fn () => $db->table('task_relations')->where('source_task_id', $a)->update(['target_task_id' => $a]), 1644, 'Task relation UPDATE trigger');
    $db->table('document_drafts')->insert(['document_id' => $doc, 'content_markdown' => 'Draft', 'updated_by_user_id' => $user]);
    $db->table('technical_context_drafts')->insert(['technical_context_id' => $ctx, 'architecture_summary' => 'Draft', 'updated_by_user_id' => $user]);
    $rejectExact(fn () => $db->table('users')->where('id', $user)->delete(), 1451, 'Referenced user deletion restricted');
    $db->table('documents')->where('id', $doc)->delete();
    $db->table('technical_contexts')->where('id', $ctx)->delete();
    $assert($db->table('document_drafts')->count() === 0 && $db->table('technical_context_drafts')->count() === 0, 'Both draft foreign keys cascade deletion');
} finally {
    $db->rollBack();
}
$deadlines = require database_path('migrations/2026_10_19_000001_add_task_deadlines.php');
$workflow = require database_path('migrations/2026_10_18_000003_add_project_documentation_workflow.php');
// DDL is deliberately exercised only on this empty, disposable destination.
$deadlines->down();
$assert(! Schema::hasColumn('tasks', 'due_date'), 'Deadline incremental down removes its columns');
$deadlines->up();
$workflow->down();
$assert(! Schema::hasTable('document_drafts') && ! Schema::hasColumn('document_change_requests', 'pending_document_id'), 'Documentation incremental down removes generated keys and drafts');
$workflow->up();
$assert(Schema::hasColumn('document_change_requests', 'pending_document_id') && Schema::hasTable('technical_context_drafts'), 'Both incrementals reapply without duplicate objects');
$report['integrity_checks'] = $checks;
$report['mysql57'] = 'PENDING: no isolated MySQL 5.7.44 instance available';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo count($checks)." additional integrity checks passed.\n";

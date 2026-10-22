<?php

// Included only after the isolated database guard and exact schema comparison.
use App\Models\AiProviderConfig;
use App\Models\AssistantAction;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\GithubConnection;
use App\Models\GithubQueryLog;
use App\Models\GithubWebhookEvent;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\TaskComment;
use App\Models\TaskRelation;
use App\Models\User;
use App\Services\ProjectDocumentationService;
use App\Services\TaskCreationService;
use App\Services\TaskLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$checks = [];
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$reject = static function (callable $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (QueryException $error) {
        if (! in_array((string) $error->getCode(), ['23000', '45000', 'HY000'])) {
            throw $error;
        }
        $check(true, $label);

        return;
    }
    throw new RuntimeException('Constraint accepted invalid data: '.$label);
};
$db->beginTransaction();
try {
    app(DatabaseSeeder::class)->run();
    $check(User::query()->count() === 0 && Role::query()->count() === 5, 'Production seeder creates roles only');
    $role = Role::query()->where('slug', 'super-admin')->sole();
    $password = bin2hex(random_bytes(20));
    $user = User::query()->create(['role_id' => $role->id, 'name' => 'Isolated validation', 'email' => 'validation@example.test', 'password_hash' => $password, 'status' => 'active']);
    $check(Hash::check($password, $user->fresh()->password_hash), 'Password hash authentication');
    Auth::guard('web')->login($user);
    $check($user->canAccessPanel(Filament\Facades\Filament::getPanel('admin')), 'Active administrator panel permission');
    $viewer = User::query()->create(['role_id' => Role::query()->where('slug', 'viewer')->sole()->id, 'name' => 'Viewer', 'email' => 'viewer@example.test', 'password_hash' => $password, 'status' => 'active']);
    $check(! $viewer->canAccessPanel(Filament\Facades\Filament::getPanel('admin')), 'Viewer panel permission denied');
    $project = Project::query()->create(['name' => 'Clean install', 'slug' => 'clean-install', 'owner_user_id' => $user->id]);
    ProjectMembership::query()->create(['project_id' => $project->id, 'user_id' => $user->id, 'granted_by_user_id' => $user->id]);
    $check($project->fresh()->owner->is($user), 'Project owner relationship');
    $service = app(ProjectDocumentationService::class);
    foreach (['overview', 'architecture'] as $section) {
        $service->saveDraft($project, $section, $section === 'overview' ? ['content_markdown' => '# Clean install'] : ['architecture_summary' => '# Architecture'], $user);
        $request = $service->submitForReview($project, $section, 'Isolated test', $user);
        $reject(fn () => DB::table($request->getTable())->insert(array_diff_key($request->getRawOriginal(), ['id' => true])), "Unique pending review: $section");
        $version = $section === 'overview'
            ? $service->decideDocument($project, $section, $request->id, 'approved', 'Validated', $user)
            : $service->decideTechnicalContext($project, $section, $request->id, 'approved', 'Validated', $user);
        $check($version !== null && $request->fresh()->status === 'approved', "Documentation publication and version: $section");
    }
    $document = Document::query()->where('project_id', $project->id)->sole();
    $check($document->currentVersion !== null && $document->draft === null, 'Document current version and draft cleanup');
    $taskService = app(TaskCreationService::class);
    $input = ['project_id' => $project->id, 'title' => 'Validate installation', 'task_type' => 'chore', 'priority' => 'medium', 'assigned_user_id' => $user->id, 'start_date' => '2026-10-20', 'due_date' => '2026-10-21'];
    $task = $taskService->create($input);
    $other = $taskService->create(array_replace($input, ['title' => 'Related task']));
    app(TaskLifecycleService::class)->transitionTo($task, 'done', 'Validation');
    $check($task->completed_at !== null && $task->due_date->format('Y-m-d') === '2026-10-21', 'Task deadlines and completion');
    $check(DB::table('task_status_histories')->where('task_id', $task->id)->count() === 1, 'Task status history');
    TaskComment::query()->create(['task_id' => $task->id, 'user_id' => $user->id, 'comment_text' => 'Validated']);
    TaskRelation::query()->create(['source_task_id' => $task->id, 'target_task_id' => $other->id, 'relation_type' => 'blocks']);
    $reject(fn () => DB::table('task_relations')->insert(['source_task_id' => $task->id, 'target_task_id' => $task->id, 'relation_type' => 'blocks']), 'Trigger rejects self relationship');
    $reject(fn () => DB::table('task_comments')->insert(['task_id' => PHP_INT_MAX, 'user_id' => $user->id, 'comment_text' => 'Invalid']), 'Foreign key rejects orphan comment');
    $otherProject = Project::query()->create(['name' => 'Other project', 'slug' => 'other-project', 'owner_user_id' => $user->id]);
    $category = DB::table('categories')->insertGetId(['project_id' => $otherProject->id, 'name' => 'Other', 'slug' => 'other']);
    $tag = DB::table('tags')->insertGetId(['project_id' => $otherProject->id, 'name' => 'Other', 'slug' => 'other']);
    $reject(fn () => DB::table('documents')->where('id', $document->id)->update(['category_id' => $category]), 'Trigger rejects cross-project category');
    $reject(fn () => DB::table('document_tags')->insert(['document_id' => $document->id, 'tag_id' => $tag]), 'Trigger rejects cross-project tag');
    $token = bin2hex(random_bytes(20));
    $github = GithubConnection::query()->create(['project_id' => $project->id, 'repository_owner' => 'validation', 'repository_name' => 'temporary', 'installation_id' => 1, 'access_token_encrypted' => $token, 'token_key_id' => 'validation']);
    $check($github->fresh()->access_token_encrypted === $token && DB::table('github_connections')->where('id', $github->id)->value('access_token_encrypted') !== $token, 'GitHub connection encrypted token round trip');
    GithubQueryLog::query()->create(['project_id' => $project->id, 'endpoint' => '/test', 'request_summary' => 'Isolated test', 'http_status' => 200, 'response_time_ms' => 1]);
    GithubWebhookEvent::query()->create(['delivery_id' => 'validation', 'event_type' => 'push', 'repository_full_name' => 'validation/temporary', 'payload_hash' => hash('sha256', 'validation')]);
    $provider = AiProviderConfig::query()->create(['provider_name' => 'validation', 'model_alias' => 'temporary', 'api_key_encrypted' => $token]);
    $conversation = AssistantConversation::query()->create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Validation']);
    $message = AssistantMessage::query()->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'message_content' => 'Validation']);
    $action = AssistantAction::query()->create(['conversation_id' => $conversation->id, 'message_id' => $message->id, 'action_type' => 'generate_task', 'target_resource_type' => 'task', 'target_resource_id' => $task->id]);
    $check($action->fresh()->conversation->is($conversation) && $provider->fresh()->api_key_encrypted === $token, 'AI configuration, conversation, message and action');
    AuditLog::query()->create(['user_id' => $user->id, 'action' => 'installation.validated', 'resource_type' => 'projects', 'resource_id' => $project->id]);
    $check(DB::table('task_comments')->count() === 1 && DB::table('github_query_logs')->count() === 1 && DB::table('github_webhook_events')->count() === 1, 'Comments, GitHub logs and webhook storage');
} finally {
    Auth::guard('web')->logout();
    $db->rollBack();
}
$report['smoke_checks'] = $checks;
$report['smoke_data_rolled_back'] = User::query()->count() === 0 && Project::query()->count() === 0;
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo count($checks)." smoke checks passed; temporary records rolled back.\n";

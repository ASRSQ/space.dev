<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectAccess;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MigrationReconciliationMysqlTest extends TestCase
{
    private ?string $views = null;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('RECONCILIATION_DATABASE');
        if (! $database) { $this->markTestSkipped('Requires the guarded isolated reconciliation runner.'); }
        $this->assertMatchesRegularExpression('/^spacedev_reconcile_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/D', $database);
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1']);
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() db')->db);
        config(['hashing.bcrypt.rounds' => 4, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::beginTransaction();
        $this->views = sys_get_temp_dir().DIRECTORY_SEPARATOR.'spacedev-reconcile-views-'.bin2hex(random_bytes(5));
        mkdir($this->views, 0700);
        config(['view.compiled' => $this->views]);
        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        if ($this->views) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            (new Filesystem)->deleteDirectory($this->views);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::create(['name' => 'Rehearsal synthetic admin', 'email' => 'rehearsal-'.bin2hex(random_bytes(6)).'@example.test', 'password_hash' => 'Rehearsal-Password-123!', 'role_id' => $role->id, 'status' => 'active']);
        $project = Project::create(['name' => 'Rehearsal synthetic project', 'slug' => 'rehearsal-'.bin2hex(random_bytes(6)), 'owner_user_id' => $user->id]);
        return [$user, $project];
    }

    private function rejects(callable $operation, int $code): void
    {
        try { $operation(); } catch (QueryException $e) {
            $this->assertSame($code, (int) ($e->errorInfo[1] ?? 0));
            return;
        }
        $this->fail('Invalid synthetic operation was accepted.');
    }

    public function test_six_triggers_reject_cross_project_categories_tags_and_self_relations(): void
    {
        [$user, $project] = $this->fixture();
        $other = DB::table('projects')->insertGetId(['name' => 'Synthetic other', 'slug' => 'rehearsal-other', 'owner_user_id' => $user->id]);
        $doc = DB::table('documents')->insertGetId(['project_id' => $project->id, 'title' => 'Synthetic', 'slug' => 'synthetic']);
        $category = DB::table('categories')->insertGetId(['project_id' => $other, 'name' => 'Synthetic foreign', 'slug' => 'synthetic']);
        $foreignTag = DB::table('tags')->insertGetId(['project_id' => $other, 'name' => 'Synthetic foreign', 'slug' => 'synthetic']);
        $localTag = DB::table('tags')->insertGetId(['project_id' => $project->id, 'name' => 'Synthetic local', 'slug' => 'synthetic']);
        $this->rejects(fn () => DB::table('documents')->insert(['project_id' => $project->id, 'title' => 'Invalid', 'slug' => 'invalid', 'category_id' => $category]), 1644);
        $this->rejects(fn () => DB::table('documents')->where('id', $doc)->update(['category_id' => $category]), 1644);
        $this->rejects(fn () => DB::table('document_tags')->insert(['document_id' => $doc, 'tag_id' => $foreignTag]), 1644);
        DB::table('document_tags')->insert(['document_id' => $doc, 'tag_id' => $localTag]);
        $this->rejects(fn () => DB::table('document_tags')->where('document_id', $doc)->update(['tag_id' => $foreignTag]), 1644);
        $task = ['project_id' => $project->id, 'title' => 'Synthetic', 'reporter_user_id' => $user->id];
        $a = DB::table('tasks')->insertGetId($task); $b = DB::table('tasks')->insertGetId($task);
        $this->rejects(fn () => DB::table('task_relations')->insert(['source_task_id' => $a, 'target_task_id' => $a, 'relation_type' => 'blocks']), 1644);
        DB::table('task_relations')->insert(['source_task_id' => $a, 'target_task_id' => $b, 'relation_type' => 'blocks']);
        $this->rejects(fn () => DB::table('task_relations')->where('source_task_id', $a)->update(['target_task_id' => $a]), 1644);
    }

    public function test_portable_pending_indexes_preserve_uniqueness_and_follow_cascades(): void
    {
        [$user, $project] = $this->fixture();
        foreach ([['documents', 'document_change_requests', 'document_id', 'pending_document_id', ['proposed_title' => 'Synthetic', 'proposed_content' => 'Synthetic']], ['technical_contexts', 'technical_context_change_requests', 'technical_context_id', 'pending_technical_context_id', ['proposed_architecture_summary' => 'Synthetic']]] as [$parent, $table, $foreign, $generated, $payload]) {
            $entity = DB::table($parent)->insertGetId(['project_id' => $project->id, 'title' => 'Synthetic', 'slug' => 'synthetic']);
            $row = $payload + [$foreign => $entity, 'proposed_version_number' => 1, 'reason' => 'Synthetic', 'author_user_id' => $user->id];
            $pending = DB::table($table)->insertGetId($row);
            $this->rejects(fn () => DB::table($table)->insert($row), 1062);
            $approved = DB::table($table)->insertGetId($row + ['status' => 'approved']);
            $this->rejects(fn () => DB::table($table)->where('id', $approved)->update(['status' => 'pending']), 1062);
            DB::table($table)->where('id', $pending)->update(['status' => 'approved']);
            DB::table($table)->where('id', $approved)->update(['status' => 'pending']);
            $newId = (int) DB::table($parent)->max('id') + 1000000;
            DB::table($parent)->where('id', $entity)->update(['id' => $newId]);
            $this->assertSame($newId, (int) DB::table($table)->where('id', $approved)->value($generated));
            $this->assertNull(DB::table($table)->where('id', $pending)->value($generated));
        }
    }

    public function test_all_nine_new_foreign_keys_and_membership_unique_indexes(): void
    {
        [$user, $project] = $this->fixture();
        $org = DB::table('organizations')->insertGetId(['name' => 'Synthetic', 'slug' => 'synthetic']);
        $team = DB::table('teams')->insertGetId(['organization_id' => $org, 'name' => 'Synthetic', 'lead_user_id' => $user->id]);
        $missing = 900000000;
        $cases = [
            ['organization_memberships', ['organization_id' => $missing, 'user_id' => $user->id]],
            ['organization_memberships', ['organization_id' => $org, 'user_id' => $missing]],
            ['teams', ['organization_id' => $missing, 'name' => 'Invalid']],
            ['teams', ['organization_id' => $org, 'name' => 'Invalid lead', 'lead_user_id' => $missing]],
            ['team_memberships', ['team_id' => $missing, 'user_id' => $user->id]],
            ['team_memberships', ['team_id' => $team, 'user_id' => $missing]],
            ['project_team', ['project_id' => $missing, 'team_id' => $team]],
            ['project_team', ['project_id' => $project->id, 'team_id' => $missing]],
        ];
        foreach ($cases as [$table, $row]) { $this->rejects(fn () => DB::table($table)->insert($row), 1452); }
        $this->rejects(fn () => DB::table('projects')->where('id', $project->id)->update(['organization_id' => $missing]), 1452);
        foreach ([['organization_memberships', ['organization_id' => $org, 'user_id' => $user->id]], ['team_memberships', ['team_id' => $team, 'user_id' => $user->id]], ['project_team', ['project_id' => $project->id, 'team_id' => $team]]] as [$table, $row]) {
            DB::table($table)->insert($row);
            $this->rejects(fn () => DB::table($table)->insert($row), 1062);
        }
        DB::table('projects')->where('id', $project->id)->update(['organization_id' => $org]);
        $this->rejects(fn () => DB::table('organizations')->where('id', $org)->delete(), 1451);
        $this->rejects(fn () => DB::table('teams')->where('id', $team)->delete(), 1451);
    }

    public function test_authentication_admin_page_and_legacy_project_access_without_permission_changes(): void
    {
        [$admin, $project] = $this->fixture();
        $this->assertTrue(Hash::check('Rehearsal-Password-123!', $admin->password_hash));
        $this->assertTrue(Auth::guard('web')->once(['email' => $admin->email, 'password' => 'Rehearsal-Password-123!']));
        $this->get('/admin/organizations')->assertOk();
        $role = Role::firstOrCreate(['slug' => 'developer'], ['name' => 'Developer']);
        $member = User::create(['name' => 'Synthetic member', 'email' => 'member-'.bin2hex(random_bytes(5)).'@example.test', 'password_hash' => 'Rehearsal-Password-123!', 'role_id' => $role->id, 'status' => 'active']);
        DB::table('project_memberships')->insert(['project_id' => $project->id, 'user_id' => $member->id, 'granted_by_user_id' => $admin->id, 'role_in_project' => 'developer', 'status' => 'active']);
        $this->assertNull($project->organization_id);
        // Filament's authenticated-session middleware keeps the password hash
        // of the previous actor; switching test actors requires a fresh session.
        session()->flush();
        $this->actingAs($member, 'web');
        $this->assertTrue(app(ProjectAccess::class)->view($member, $project));
        $this->assertTrue(app(ProjectAccess::class)->contribute($member, $project));
        $this->assertFalse(app(ProjectAccess::class)->manage($member, $project));
        $this->assertFalse(Gate::allows('create', User::class));
        $this->get('/admin/organizations')->assertForbidden();
        $this->assertSame('developer', $member->fresh()->role->slug);
        $this->assertFalse(Auth::guard('web')->once(['email' => $member->email, 'password' => 'wrong-password']));
    }

    public function test_organization_access_requires_active_membership_and_same_organization(): void
    {
        [$admin, $project] = $this->fixture();
        $role = Role::firstOrCreate(['slug' => 'developer'], ['name' => 'Developer']);
        $member = User::create(['name' => 'Synthetic org member', 'email' => 'org-'.bin2hex(random_bytes(5)).'@example.test', 'password_hash' => 'Rehearsal-Password-123!', 'role_id' => $role->id, 'status' => 'active']);
        DB::table('project_memberships')->insert(['project_id' => $project->id, 'user_id' => $member->id, 'granted_by_user_id' => $admin->id, 'role_in_project' => 'developer', 'status' => 'active']);
        $org = DB::table('organizations')->insertGetId(['name' => 'Synthetic org', 'slug' => 'synthetic-org']);
        DB::table('projects')->where('id', $project->id)->update(['organization_id' => $org]);
        $project->refresh();
        $access = app(ProjectAccess::class);
        $this->assertFalse($access->view($member, $project));
        $membership = DB::table('organization_memberships')->insertGetId(['organization_id' => $org, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);
        $this->assertTrue($access->view($member, $project));
        DB::table('organization_memberships')->where('id', $membership)->update(['status' => 'inactive']);
        $this->assertFalse($access->view($member, $project));
    }

    public function test_portable_workflow_migration_is_a_noop_on_fresh_shape_and_blocks_rollback(): void
    {
        $before = [];
        foreach (['document_change_requests', 'technical_context_change_requests'] as $table) {
            $before[$table] = (array) DB::selectOne("SHOW CREATE TABLE `$table`");
        }
        $migration = require database_path('migrations/2026_10_28_000001_normalize_workflow_pending_indexes.php');
        $migration->up();
        foreach ($before as $table => $ddl) { $this->assertSame($ddl, (array) DB::selectOne("SHOW CREATE TABLE `$table`")); }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rollback blocked');
        $migration->down();
    }

    public function test_developer_editor_and_viewer_permissions_with_mysql_relationships(): void
    {
        [$admin, $project] = $this->fixture();
        foreach ([['developer', 'developer', true, true], ['user', 'editor', true, false], ['viewer', 'viewer', false, false]] as [$global, $projectRole, $content, $tasks]) {
            $role = Role::firstOrCreate(['slug' => $global], ['name' => $global]);
            $user = User::create(['name' => 'Synthetic '.$projectRole, 'email' => 'matrix-'.bin2hex(random_bytes(5)).'@example.test', 'password_hash' => 'Rehearsal-Password-123!', 'role_id' => $role->id, 'status' => 'active']);
            DB::table('project_memberships')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'granted_by_user_id' => $admin->id, 'role_in_project' => $projectRole, 'status' => 'active']);
            $gate = Gate::forUser($user);
            $this->assertTrue($gate->allows('view-project-documentation', $project));
            $this->assertSame($content, $gate->allows('edit-project-documentation', $project));
            $this->assertSame($tasks, app(ProjectAccess::class)->contribute($user, $project));
            $this->assertFalse($gate->allows('review-project-documentation', $project));
            $this->assertFalse($gate->allows('create', User::class));
            // Inactive synthetic accounts must fail password authentication.
            $user->update(['status' => 'inactive']);
            $this->assertFalse(Auth::guard('web')->once(['email' => $user->email, 'password' => 'Rehearsal-Password-123!']));
        }
    }
}

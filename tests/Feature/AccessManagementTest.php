<?php

namespace Tests\Feature;

use App\Filament\Pages\PermissionMatrix;
use App\Filament\Resources\Organizations\Pages\ManageOrganizations;
use App\Filament\Resources\Problems\Pages\CreateProblem;
use App\Filament\Resources\ProjectMemberships\Pages\ManageProjectMemberships;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\TaskKanban;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PromptTemplate;
use App\Models\Role;
use App\Models\Task;
use App\Models\Team;
use App\Models\TechnicalContext;
use App\Models\User;
use App\Services\OrganizationAdministrationService;
use App\Services\ProjectAccess;
use App\Services\ProjectMembershipService;
use App\Services\TaskAssignmentService;
use App\Services\TaskCreationService;
use App\Services\TaskLifecycleService;
use App\Services\UserAdministrationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    public function test_problem_checklists_follow_membership_permissions_and_revocation(): void
    {
        [$admin, $member, $project] = $this->scenario();
        app(ProjectMembershipService::class)->grant($project->id, $member->id, 'developer_editor');
        $this->actingAs($member, 'web');
        $markdown = "# Erro\n\n- [ ] Verificar";
        Livewire::test(CreateProblem::class)->fillForm([
            'project_id' => $project->id, 'title' => 'Erro integrado',
            'description' => $markdown, 'severity' => 'high', 'status' => 'open',
        ])->call('create')->assertHasNoFormErrors();
        $problem = Problem::query()->sole();
        $this->get('/admin/problems')->assertOk();
        $view = \App\Filament\Resources\Problems\Pages\ViewProblem::class;
        $page = Livewire::test($view, ['record' => $problem->id]);
        $page->call('toggleChecklist', 3, hash('sha256', $markdown), true);
        $checked = str_replace('[ ]', '[x]', $markdown);
        $this->assertSame($checked, $problem->fresh()->description);
        Livewire::test($view, ['record' => $problem->id])->assertSee('checked=""', false);
        Livewire::test(\App\Filament\Resources\Problems\Pages\EditProblem::class, ['record' => $problem->id])
            ->fillForm(['title' => 'Erro editado por programador'])->call('save')->assertHasNoFormErrors();
        $this->actingAs($admin, 'web');
        app(ProjectMembershipService::class)->grant($project->id, $member->id, 'editor');
        $this->actingAs($member, 'web');
        $this->assertFalse(Gate::allows('update', $problem->fresh()));
        $this->get('/admin/problems/'.$problem->id)->assertOk();
        $this->get('/admin/problems/'.$problem->id.'/edit')->assertForbidden();
        $this->assertFalse(\App\Filament\Resources\Users\UserResource::canViewAny());
        $page->call('toggleChecklist', 3, hash('sha256', $checked), false)->assertForbidden();
        $this->assertSame($checked, $problem->fresh()->description);
        $this->assertSame('developer', $member->fresh()->role->slug);
    }

    private ?string $views = null;

    private bool $mysql = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = getenv('EDITOR_MYSQL_VALIDATION') === '1';
        if ($this->mysql) {
            $database = getenv('EDITOR_MYSQL_DATABASE');
            $this->assertMatchesRegularExpression('/^spacedev_editor_validation_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/', $database);
            $this->assertSame('testing', app()->environment());
            $this->assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1']);
            $this->assertEmpty(config('database.connections.mysql.url'));
            config(['database.default' => 'mysql']);
            DB::setDefaultConnection('mysql');
            $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS db')->db);
            DB::beginTransaction();
        } else {
            $this->createSchema();
            (require database_path('migrations/2026_10_23_000001_add_organization_access_management.php'))->up();
        }
        $this->views = sys_get_temp_dir().DIRECTORY_SEPARATOR.'spacedev-access-'.bin2hex(random_bytes(5));
        mkdir($this->views, 0777, true);
        config(['view.compiled' => $this->views]);
        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        if ($this->mysql) {
            while (DB::connection('mysql')->transactionLevel() > 0) {
                DB::connection('mysql')->rollBack();
            }
        }
        if ($this->views) {
            (new Filesystem)->deleteDirectory($this->views);
        }
        parent::tearDown();
    }

    public function test_alex_adds_lauro_and_assigns_sc001_without_elevating_lauro(): void
    {
        $alex = $this->user('admin', 'Alex');
        $lauro = $this->user('developer', 'Lauro');
        $project = $this->project($alex, 'SPACE Commerce');
        $task = $this->task($project, $alex, 'SC-001');
        $this->actingAs($alex, 'web');
        $this->assertArrayNotHasKey($lauro->id, app(TaskAssignmentService::class)->optionsForProject($project->id));
        Livewire::test(ManageProjectMemberships::class)->callAction('create', ['project_id' => $project->id, 'user_id' => $lauro->id, 'role_in_project' => 'developer_editor'])->assertHasNoActionErrors();
        Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['assigned_user_id' => $lauro->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($lauro->id, $task->fresh()->assigned_user_id);
        Livewire::test(EditTask::class, ['record' => $task->id])->assertFormSet(['assigned_user_id' => $lauro->id])->fillForm(['title' => 'SC-001 revisada'])->call('save')->assertHasNoFormErrors();
        Livewire::test(ViewTask::class, ['record' => $task->id])->assertSee('Lauro');
        Livewire::test(TaskKanban::class)->assertSee('Lauro');
        $this->actingAs($lauro, 'web');
        $this->get('/admin/tasks/'.$task->id)->assertOk();
        Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['title' => 'SC-001 atualizada por Lauro'])->call('save')->assertHasNoFormErrors();
        app(TaskLifecycleService::class)->transitionTo($task->fresh(), 'in_progress');
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame('developer', $lauro->fresh()->role->slug);
        $this->get('/admin/users')->assertForbidden();
        $this->assertFalse(Gate::allows('review-project-documentation', $project));
    }

    public function test_revoking_membership_preserves_history_and_current_assignment(): void
    {
        [$admin,$member,$project,$task] = $this->scenario();
        $grant = $project->memberships()->where('user_id', $member->id)->sole();
        app(ProjectMembershipService::class)->revoke($grant);
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertSame($admin->id, $grant->fresh()->revoked_by_user_id);
        $this->assertSame($member->id, $task->fresh()->assigned_user_id);
        Livewire::test(EditTask::class, ['record' => $task->id])->assertFormSet(['assigned_user_id' => $member->id])->call('save')->assertHasNoFormErrors();
        $this->actingAs($member, 'web');
        $this->get('/admin/tasks/'.$task->id)->assertForbidden();
    }

    public function test_role_changes_create_new_grants_instead_of_erasing_old_roles(): void
    {
        [, $member,$project] = $this->scenario();
        app(ProjectMembershipService::class)->grant($project->id, $member->id, 'editor');
        $this->assertSame(2, $project->memberships()->count());
        $this->assertSame(1, $project->memberships()->where('status', 'active')->count());
        $this->assertSame('developer', $project->memberships()->where('status', 'revoked')->sole()->role_in_project);
    }

    public function test_viewer_has_read_only_access_and_editor_cannot_distribute_tasks(): void
    {
        [$admin,,$project,$task] = $this->scenario();
        foreach (['viewer', 'editor'] as $role) {
            $member = $this->user($role === 'viewer' ? 'viewer' : 'user');
            app(ProjectMembershipService::class)->grant($project->id, $member->id, $role);
            session()->flush();
            $this->actingAs($member, 'web');
            $this->get('/admin/tasks/'.$task->id)->assertOk();
            Livewire::test(TaskKanban::class)->assertDontSee('aria-label="Criar tarefa em', false);
            $this->assertFalse(Gate::allows('assign', $task));
            $this->assertFalse(Gate::allows('update', $task));
            $this->assertSame($role === 'editor', Gate::allows('edit-project-documentation', $project));
            $this->assertFalse(Gate::allows('review-project-documentation', $project));
            $this->actingAs($admin, 'web');
        }
    }

    public function test_project_admin_can_manage_members_but_cannot_approve_or_promote_global_roles(): void
    {
        [$admin,$lead,$project] = $this->scenario();
        app(ProjectMembershipService::class)->grant($project->id, $lead->id, 'lead');
        $candidate = $this->user('developer');
        $this->actingAs($lead, 'web');
        app(ProjectMembershipService::class)->grant($project->id, $candidate->id, 'developer');
        $this->assertTrue(Gate::allows('manageMembers', $project));
        $this->assertFalse(Gate::allows('review-project-documentation', $project));
        $this->get('/admin/users')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(UserAdministrationService::class)->save(['name' => 'Elevado', 'email' => $lead->email, 'role_id' => $admin->role_id, 'status' => 'active'], $lead);
    }

    public function test_forged_assignment_by_a_developer_is_rejected(): void
    {
        [$admin,$member,$project,$task] = $this->scenario();
        $other = $this->user('developer');
        app(ProjectMembershipService::class)->grant($project->id, $other->id, 'developer');
        $this->actingAs($member, 'web');
        $attempt = Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['assigned_user_id' => $other->id])->call('save');
        if ($attempt->status() === 403) {
            $attempt->assertForbidden();
        } else {
            $attempt->assertHasFormErrors(['assigned_user_id']);
        }
        $this->assertSame($member->id, $task->fresh()->assigned_user_id);
    }

    public function test_inactive_or_foreign_organization_users_cannot_be_added_or_assigned(): void
    {
        [$admin,,$project,$task] = $this->scenario();
        $user = $this->user('developer');
        $user->update(['status' => 'inactive']);
        try {
            app(ProjectMembershipService::class)->grant($project->id, $user->id, 'developer');
            $this->fail('Inactive user accepted');
        } catch (ValidationException) {
        }
        $this->assertFalse(app(TaskAssignmentService::class)->isEligible($user->id, $project->id));
        $this->assertArrayNotHasKey($user->id, app(TaskAssignmentService::class)->optionsForProject($project->id));
        Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['assigned_user_id' => $user->id])->call('save')->assertHasFormErrors(['assigned_user_id']);
    }

    public function test_admin_creates_edits_and_resets_user_password_without_exposing_hash(): void
    {
        $admin = $this->user('admin', 'Alex');
        $role = $this->role('developer');
        $this->actingAs($admin, 'web');
        Livewire::test(CreateUser::class)->fillForm(['name' => 'Lauro', 'email' => 'lauro@example.test', 'role_id' => $role->id, 'status' => 'active', 'password' => 'Safe-Test-password9!', 'password_confirmation' => 'Safe-Test-password9!'])->call('create')->assertHasNoFormErrors();
        $user = User::where('email', 'lauro@example.test')->sole();
        $this->assertTrue(Hash::check('Safe-Test-password9!', $user->password_hash));
        $hash = $user->password_hash;
        Livewire::test(EditUser::class, ['record' => $user->id])->assertFormSet(['password' => null])->fillForm(['name' => 'Lauro editado'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($hash, $user->fresh()->password_hash);
        Livewire::test(EditUser::class, ['record' => $user->id])->fillForm(['password' => 'New-Test-password9!', 'password_confirmation' => 'New-Test-password9!'])->call('save')->assertHasNoFormErrors()->assertFormSet(['password' => null, 'password_confirmation' => null]);
        $this->assertTrue(Hash::check('New-Test-password9!', $user->fresh()->password_hash));
        $this->assertArrayNotHasKey('password_hash', $user->fresh()->toArray());
    }

    public function test_admin_cannot_self_demote_disable_or_assign_super_admin(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');
        $developer = $this->role('developer');
        foreach ([['role_id' => $developer->id, 'status' => 'active'], ['role_id' => $admin->role_id, 'status' => 'inactive'], ['role_id' => $this->role('super-admin')->id, 'status' => 'active']] as $change) {
            try {
                app(UserAdministrationService::class)->save(['name' => $admin->name, 'email' => $admin->email] + $change, $admin);
                $this->fail('Unsafe self update accepted');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('admin', $admin->fresh()->role->slug);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_user_deactivation_preserves_task_history_and_revokes_sessions(): void
    {
        [, $member,,$task] = $this->scenario();
        app(UserAdministrationService::class)->save(['name' => $member->name, 'email' => $member->email, 'role_id' => $member->role_id, 'status' => 'inactive'], $member);
        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame($member->id, $task->fresh()->assigned_user_id);
        $this->assertFalse(Gate::allows('delete', $member));
        $this->assertNull(auth()->guard('web')->getProvider()->retrieveById($member->id));
    }

    public function test_organization_and_team_forms_use_authorized_services(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');
        Livewire::test(ManageOrganizations::class)->callAction('create', ['name' => 'Empresa A', 'slug' => 'empresa-a', 'description' => 'Administrativa'])->assertHasNoActionErrors();
        $org = Organization::where('slug', 'empresa-a')->sole();
        app(OrganizationAdministrationService::class)->setMember($org->id, $admin->id, 'admin', 'active');
        Livewire::test(ManageTeams::class)->callAction('create', ['organization_id' => $org->id, 'name' => 'Engenharia', 'lead_user_id' => $admin->id])->assertHasNoActionErrors();
        $team = Team::where('organization_id', $org->id)->sole();
        $this->assertSame($admin->id, $team->lead_user_id);
        $this->assertSame(1, $team->memberships()->count());
    }

    public function test_organization_isolation_blocks_even_a_forged_project_membership(): void
    {
        [$admin,$member,$project,$task] = $this->scenario();
        $org = app(OrganizationAdministrationService::class)->save(['name' => 'A', 'slug' => 'a']);
        app(OrganizationAdministrationService::class)->setMember($org->id, $admin->id, 'admin', 'active');
        app(OrganizationAdministrationService::class)->setMember($org->id, $member->id, 'member', 'active');
        app(OrganizationAdministrationService::class)->linkProject($org, $project->id);
        $foreign = $this->user('developer');
        $other = $this->project($admin, 'Outro projeto');
        app(ProjectMembershipService::class)->grant($other->id, $foreign->id, 'developer');
        ProjectMembership::create(['project_id' => $project->id, 'user_id' => $foreign->id, 'role_in_project' => 'lead', 'status' => 'active', 'granted_by_user_id' => $admin->id]);
        $this->actingAs($foreign, 'web');
        $this->assertFalse(Gate::allows('view', $project->fresh()));
        $this->get('/admin/tasks/'.$task->id)->assertNotFound();
        $this->get('/admin/projects/'.$project->id.'/documentation')->assertNotFound();
        Livewire::test(TaskKanban::class)->assertDontSee($task->title)->assertDontSee($project->name);
        $this->assertSame([], app(TaskAssignmentService::class)->optionsForProject($project->id));
    }

    public function test_revoked_organization_membership_removes_project_access_but_preserves_assignments(): void
    {
        [$admin,$member,$project,$task] = $this->scenario();
        $org = app(OrganizationAdministrationService::class)->save(['name' => 'A', 'slug' => 'a']);
        app(OrganizationAdministrationService::class)->setMember($org->id, $admin->id, 'admin', 'active');
        app(OrganizationAdministrationService::class)->setMember($org->id, $member->id, 'member', 'active');
        app(OrganizationAdministrationService::class)->linkProject($org, $project->id);
        app(OrganizationAdministrationService::class)->setMember($org->id, $member->id, 'member', 'revoked');
        $this->assertFalse(app(TaskAssignmentService::class)->isEligible($member->id, $project->id));
        $this->assertSame($member->id, $task->fresh()->assigned_user_id);
        $this->actingAs($member, 'web');
        $this->assertFalse(Gate::allows('view', $task->fresh()));
    }

    public function test_team_relationships_do_not_grant_project_access_or_cross_organization_access(): void
    {
        [$admin,$member,$project] = $this->scenario();
        $service = app(OrganizationAdministrationService::class);
        $org = $service->save(['name' => 'A', 'slug' => 'a']);
        $other = $service->save(['name' => 'B', 'slug' => 'b']);
        $service->setMember($org->id, $admin->id, 'admin', 'active');
        $service->setMember($org->id, $member->id, 'member', 'active');
        $service->linkProject($org, $project->id);
        $candidate = $this->user('developer');
        $service->setMember($org->id, $candidate->id, 'member', 'active');
        $team = $service->saveTeam(['organization_id' => $org->id, 'name' => 'Equipe', 'lead_user_id' => $member->id]);
        $service->setTeamMember($team, $candidate->id, 'active');
        $service->linkTeamProject($team, $project->id, true);
        $this->assertFalse(app(TaskAssignmentService::class)->isEligible($candidate->id, $project->id));
        $this->assertFalse(app(ProjectAccess::class)->view($candidate, $project->fresh()));
        try {
            $service->saveTeam(['organization_id' => $other->id, 'name' => 'Movida', 'lead_user_id' => null], $team);
            $this->fail('Cross organization move accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($org->id, $team->fresh()->organization_id);
    }

    public function test_last_organization_admin_and_team_lead_cannot_be_removed_accidentally(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');
        $service = app(OrganizationAdministrationService::class);
        $org = $service->save(['name' => 'A', 'slug' => 'a']);
        $service->setMember($org->id, $admin->id, 'admin', 'active');
        try {
            $service->setMember($org->id, $admin->id, 'member', 'revoked');
            $this->fail('Last organization admin removed');
        } catch (ValidationException) {
        }
        $team = $service->saveTeam(['organization_id' => $org->id, 'name' => 'Equipe', 'lead_user_id' => $admin->id]);
        $this->expectException(ValidationException::class);
        $service->setTeamMember($team, $admin->id, 'revoked');
    }

    public function test_permission_matrix_is_read_only_and_restricted_to_platform_admins(): void
    {
        [$admin,$member,$project] = $this->scenario();
        Livewire::test(PermissionMatrix::class)->set('userId', (string) $member->id)->set('projectId', (string) $project->id)->assertSee('Programador')->assertSee('Negado');
        $this->actingAs($member, 'web');
        Livewire::test(PermissionMatrix::class)->assertForbidden();
        $this->assertSame('developer', $member->fresh()->role->slug);
    }

    public function test_archival_and_forged_project_member_requests_are_authorized_on_server(): void
    {
        [, $member,$project,$task] = $this->scenario();
        $outsider = $this->user('developer');
        $this->actingAs($member, 'web');
        try {
            app(ProjectMembershipService::class)->grant($project->id, $outsider->id, 'lead');
            $this->fail('Member promoted another user');
        } catch (AuthorizationException) {
        }
        $this->assertFalse(Gate::allows('archive', $project));
        $this->assertFalse(Gate::allows('delete', $task));
        $this->assertSame(1, $project->memberships()->where('status', 'active')->count());
    }

    public function test_org_admin_is_scoped_and_cannot_link_unknown_global_accounts(): void
    {
        [$admin,$member,$project] = $this->scenario();
        $service = app(OrganizationAdministrationService::class);
        $a = $service->save(['name' => 'Organização A', 'slug' => 'a']);
        $b = $service->save(['name' => 'Organização B', 'slug' => 'b']);
        $service->setMember($a->id, $admin->id, 'admin', 'active');
        $service->setMember($a->id, $member->id, 'admin', 'active');
        $service->linkProject($a, $project->id);
        $unknown = $this->user('developer');
        $this->actingAs($member, 'web');
        $this->assertTrue(Gate::allows('update', $a));
        $this->assertFalse(Gate::allows('view', $b));
        $this->assertFalse(Gate::allows('review-project-documentation', $project->fresh()));
        Livewire::test(ManageOrganizations::class)->assertSee('Organização A')->assertDontSee('Organização B');
        try {
            $service->setMember($a->id, $unknown->id, 'admin', 'active');
            $this->fail('Organization admin linked unknown global account');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            $service->save(['name' => 'Modificada', 'slug' => 'b'], $b);
            $this->fail('Organization B modified');
        } catch (AuthorizationException) {
        }
        $this->assertSame('Organização B', $b->fresh()->name);
    }

    public function test_task_and_problem_creation_are_scoped_even_with_forged_project_ids(): void
    {
        [$admin,$member,$project] = $this->scenario();
        $foreign = $this->project($admin, 'Segredo');
        $this->actingAs($member, 'web');
        $input = ['project_id' => $project->id, 'title' => 'Tarefa própria', 'task_type' => 'feature', 'priority' => 'medium'];
        $created = app(TaskCreationService::class)->create($input);
        $this->assertSame($member->id, $created->reporter_user_id);
        try {
            app(TaskCreationService::class)->create(array_replace($input, ['project_id' => $foreign->id]));
            $this->fail('Foreign task created');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Livewire::test(CreateProblem::class)->fillForm(['project_id' => $project->id, 'title' => 'Problema técnico', 'description' => '# Sintomas', 'severity' => 'medium', 'status' => 'open'])->call('create')->assertHasNoFormErrors();
        $problem = Problem::where('title', 'Problema técnico')->sole();
        $this->assertSame($member->id, $problem->reporter_user_id);
        $this->assertFalse(Gate::allows('delete', $problem));
    }

    public function test_content_policies_isolate_prompts_and_do_not_grant_approval_to_members(): void
    {
        [$admin,$member,$project] = $this->scenario();
        foreach ([Document::class, TechnicalContext::class, PromptTemplate::class] as $model) {
            $content = new $model(['project_id' => $project->id]);
            $content->setRelation('project', $project);
            $gate = Gate::forUser($member);
            $this->assertTrue($gate->allows('view', $content));
            $this->assertTrue($gate->allows('create', [$model, $project]));
            $this->assertTrue($gate->allows('update', $content));
            $this->assertFalse($gate->allows('delete', $content));
            $this->assertFalse($gate->allows('archive', $content));
            $this->assertFalse($gate->allows('review', $content));
        }
        $prompt = new PromptTemplate;
        $this->assertFalse(Gate::forUser($member)->allows('view', $prompt));
        $this->assertFalse(Gate::forUser($admin)->allows('review', $prompt));
    }

    public function test_assignment_diagnostic_matches_filters_and_readers_are_not_assignable(): void
    {
        [$admin,$member,$project] = $this->scenario();
        $candidate = $this->user('developer');
        $service = app(TaskAssignmentService::class);
        $this->assertStringContainsString('vínculo ativo', $service->eligibilityMessage($candidate->id, $project->id));
        app(ProjectMembershipService::class)->grant($project->id, $candidate->id, 'editor');
        $this->assertFalse($service->isEligible($candidate->id, $project->id));
        $this->assertStringContainsString('papel no projeto', $service->eligibilityMessage($candidate->id, $project->id));
        app(ProjectMembershipService::class)->grant($project->id, $candidate->id, 'developer_editor');
        $this->assertTrue($service->isEligible($candidate->id, $project->id));
        $candidate->update(['status' => 'inactive']);
        $this->assertStringContainsString('não está ativa', $service->eligibilityMessage($candidate->id, $project->id));
        $reader = $this->user('viewer');
        app(ProjectMembershipService::class)->grant($project->id, $reader->id, 'viewer');
        $this->assertArrayNotHasKey($reader->id, $service->optionsForProject($project->id));
    }

    public function test_non_manager_cannot_archive_their_task(): void
    {
        [, $member,,$task] = $this->scenario();
        $this->actingAs($member, 'web');
        $this->expectException(AuthorizationException::class);
        app(TaskLifecycleService::class)->archive($task);
    }

    public function test_password_rules_and_remember_token_are_enforced_on_deactivation(): void
    {
        [, $member] = $this->scenario();
        $service = app(UserAdministrationService::class);
        try {
            $service->save(['name' => $member->name, 'email' => $member->email, 'role_id' => $member->role_id, 'status' => 'active', 'password' => 'simplepassword', 'password_confirmation' => 'simplepassword'], $member);
            $this->fail('Weak password accepted');
        } catch (ValidationException) {
        }
        $member->remember_token = 'old-token';
        $member->save();
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $member->id, 'payload' => 'synthetic', 'last_activity' => time()]);
        $service->save(['name' => $member->name, 'email' => $member->email, 'role_id' => $member->role_id, 'status' => 'inactive'], $member);
        $this->assertNull($member->fresh()->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
    }

    private function scenario(): array
    {
        $admin = $this->user('admin', 'Alex');
        $member = $this->user('developer', 'Lauro');
        $project = $this->project($admin, 'SPACE Commerce');
        $this->actingAs($admin, 'web');
        app(ProjectMembershipService::class)->grant($project->id, $member->id, 'developer');
        $task = $this->task($project, $admin, 'SC-001');
        $task->update(['assigned_user_id' => $member->id]);

        return [$admin, $member, $project, $task];
    }

    private function role(string $slug): Role
    {
        return Role::firstOrCreate(['slug' => $slug], ['name' => $slug === 'developer' ? 'Programador' : ucfirst($slug)]);
    }

    private function user(string $role, string $name = 'Usuário teste'): User
    {
        return User::create(['role_id' => $this->role($role)->id, 'name' => $name, 'email' => bin2hex(random_bytes(8)).'@example.test', 'password_hash' => 'test-only', 'status' => 'active']);
    }

    private function project(User $owner, string $name): Project
    {
        return Project::create(['name' => $name, 'slug' => 'project-'.bin2hex(random_bytes(6)), 'owner_user_id' => $owner->id, 'status' => 'active']);
    }

    private function task(Project $project, User $reporter, string $title): Task
    {
        $task = new Task(['title' => $title, 'project_id' => $project->id, 'description' => "# Diagnóstico\n\n- [ ] Revisar", 'task_type' => 'feature', 'priority' => 'medium']);
        $task->reporter_user_id = $reporter->id;
        $task->status = 'todo';
        $task->save();

        return $task;
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id');
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password_hash');
            $t->string('remember_token')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable();
            $t->text('payload');
            $t->integer('last_activity');
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->text('description')->nullable();
            $t->string('repository_url')->nullable();
            $t->foreignId('owner_user_id');
            $t->string('status')->default('active');
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('project_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id');
            $t->foreignId('user_id');
            $t->string('role_in_project');
            $t->string('status');
            $t->foreignId('granted_by_user_id');
            $t->dateTime('granted_at')->nullable();
            $t->foreignId('revoked_by_user_id')->nullable();
            $t->dateTime('revoked_at')->nullable();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id');
            $t->foreignId('assigned_user_id')->nullable();
            $t->foreignId('reporter_user_id');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('task_type');
            $t->string('priority');
            $t->string('status');
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('task_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id');
            $t->foreignId('user_id');
            $t->text('comment_text');
            $t->timestamps();
        });
        Schema::create('task_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_task_id');
            $t->foreignId('target_task_id');
            $t->string('relation_type');
        });
        Schema::create('task_status_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id');
            $t->foreignId('changed_by_user_id');
            $t->string('old_status');
            $t->string('new_status');
            $t->string('reason')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('problems', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id');
            $t->foreignId('reporter_user_id');
            $t->string('title');
            $t->text('description');
            $t->string('severity')->default('medium');
            $t->string('status')->default('open');
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
        });
    }
}

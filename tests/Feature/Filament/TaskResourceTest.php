<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\TaskKanban;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskRelation;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\TaskAssignmentService;
use App\Services\TaskCreationService;
use App\Services\TaskLifecycleService;
use App\Support\TaskMarkdown;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class TaskResourceTest extends TestCase
{
    private static string $compiledViewsPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$compiledViewsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'spacedev-task-resource-views-'.bin2hex(random_bytes(6));
        mkdir(self::$compiledViewsPath, 0777, true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['view.compiled' => self::$compiledViewsPath]);

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role_in_project', 50)->default('contributor');
            $table->string('status')->default('active');
            $table->dateTime('granted_at')->useCurrent();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->mediumText('description')->nullable();
            $table->string('task_type');
            $table->string('priority')->default('medium');
            $table->string('status')->default('todo');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_10_19_000001_add_task_deadlines.php'))->up();

        Schema::create('task_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('target_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('relation_type');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['source_task_id', 'target_task_id', 'relation_type']);
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('comment_text');
            $table->timestamps();
        });

        Schema::create('task_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('old_status', 50);
            $table->string('new_status', 50);
            $table->string('reason', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$compiledViewsPath);

        parent::tearDownAfterClass();
    }

    public function test_admin_and_super_admin_can_open_the_task_resource_but_other_roles_cannot(): void
    {
        foreach (['admin', 'super-admin'] as $role) {
            $user = $this->makeUser($role);
            Auth::guard('web')->login($user);

            $this->get('/admin/tasks')->assertOk();
            Auth::forgetGuards();
        }

        $developer = $this->makeUser('developer');
        Auth::guard('web')->login($developer);
        $this->get('/admin/tasks')->assertForbidden();
    }

    public function test_admin_can_create_task_with_authenticated_reporter_and_active_member_assignee(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $assignee = $this->makeUser('developer');
        $project = $this->makeProject($owner);
        $this->makeMembership($project, $assignee, $admin);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->getKey(),
                'title' => 'New task',
                'description' => 'Test description',
                'task_type' => 'feature',
                'priority' => 'high',
                'assigned_user_id' => $assignee->getKey(),
                'reporter_user_id' => $owner->getKey(),
                'status' => 'done',
                'is_archived' => true,
                'archived_at' => now(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();
        $this->assertSame($admin->getKey(), $task->reporter_user_id);
        $this->assertSame($assignee->getKey(), $task->assigned_user_id);
        $this->assertSame('todo', $task->status);
        $this->assertFalse($task->is_archived);
        $this->assertNull($task->archived_at);
        $this->assertSame($project->getKey(), $task->project_id);
    }

    public function test_mass_assignment_cannot_change_task_lifecycle_or_reporter_fields(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $protected = [
            'status' => 'done',
            'reporter_user_id' => $this->makeUser('user')->getKey(),
            'is_archived' => true,
            'archived_at' => now(),
        ];

        $task->fill($protected);
        $this->assertSame('todo', $task->status);
        $this->assertSame($admin->getKey(), $task->reporter_user_id);
        $this->assertFalse($task->is_archived);
        $this->assertNull($task->archived_at);

        $task->update($protected);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->getKey(),
            'status' => 'todo',
            'reporter_user_id' => $admin->getKey(),
            'is_archived' => false,
            'archived_at' => null,
        ]);

        $countBefore = Task::query()->count();

        try {
            Task::query()->create(array_merge([
                'project_id' => $project->getKey(),
                'title' => 'Untrusted mass assignment',
                'task_type' => 'feature',
                'priority' => 'medium',
            ], $protected));
            $this->fail('Mass assignment must not provide the required reporter field.');
        } catch (QueryException) {
            $this->assertSame($countBefore, Task::query()->count());
        }
    }

    public function test_task_form_requires_a_title_and_rejects_values_outside_the_database_enums(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->getKey(),
                'title' => '',
                'task_type' => 'unsupported_type',
                'priority' => 'critical',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'title' => 'required',
                'task_type',
                'priority',
            ]);

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->getKey(),
                'title' => str_repeat('t', 201),
                'task_type' => 'feature',
                'priority' => 'medium',
            ])
            ->call('create')
            ->assertHasFormErrors(['title']);

        $this->assertSame(0, Task::query()->count());
    }

    public function test_owner_without_active_membership_is_not_an_eligible_assignee(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $project = $this->makeProject($owner);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->getKey(),
                'title' => 'Owner assignment must fail',
                'task_type' => 'bug',
                'priority' => 'medium',
                'assigned_user_id' => $owner->getKey(),
            ])
            ->call('create')
            ->assertHasFormErrors(['assigned_user_id']);

        $this->assertSame(0, Task::query()->count());
    }

    public function test_inactive_user_or_revoked_membership_cannot_be_assigned(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $inactive = $this->makeUser('developer', 'inactive');
        $revoked = $this->makeUser('developer');
        $project = $this->makeProject($owner);
        $this->makeMembership($project, $inactive, $admin);
        $this->makeMembership($project, $revoked, $admin, 'revoked');

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        foreach ([$inactive, $revoked] as $user) {
            Livewire::test(CreateTask::class)
                ->fillForm([
                    'project_id' => $project->getKey(),
                    'title' => 'Ineligible assignee',
                    'task_type' => 'bug',
                    'priority' => 'medium',
                    'assigned_user_id' => $user->getKey(),
                ])
                ->call('create')
                ->assertHasFormErrors(['assigned_user_id']);
        }

        $this->assertSame(0, Task::query()->count());
    }

    public function test_existing_assignee_is_preserved_when_membership_is_revoked(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('user');
        $assignee = $this->makeUser('developer');
        $project = $this->makeProject($owner);
        $membership = $this->makeMembership($project, $assignee, $admin);
        $task = $this->makeTask($project, $admin, ['assigned_user_id' => $assignee->getKey()]);
        $membership->status = 'revoked';
        $membership->save();

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormSet(['assigned_user_id' => $assignee->getKey()])
            ->assertFormFieldExists('assigned_user_id', fn ($field): bool => str_contains($field->getOptions()[$assignee->id], 'não elegível'))
            ->fillForm([
                'title' => 'Changed while assignment is invalid',
                'assigned_user_id' => $assignee->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Changed while assignment is invalid', $task->fresh()->title);
        $this->assertSame($assignee->getKey(), $task->fresh()->assigned_user_id);
    }

    public function test_creation_is_restricted_to_active_projects_and_paused_projects_remain_editable(): void
    {
        $admin = $this->makeUser('admin');
        $pausedProject = $this->makeProject($admin, ['status' => 'paused']);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $pausedProject->getKey(),
                'title' => 'Must not be created',
                'task_type' => 'feature',
                'priority' => 'low',
            ])
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        $task = $this->makeTask($pausedProject, $admin);
        Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])
            ->fillForm(['title' => 'Edited in paused project'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edited in paused project', $task->fresh()->title);
    }

    public function test_archived_project_tasks_are_read_only_even_by_direct_edit_url(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, [
            'status' => 'archived',
            'is_archived' => true,
            'archived_at' => now(),
        ]);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');

        $this->get('/admin/tasks/'.$task->getKey())->assertOk();
        $this->get('/admin/tasks/'.$task->getKey().'/edit')->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->getKey(), 'title' => 'Original task']);

        $this->expectException(AuthorizationException::class);
        app(TaskLifecycleService::class)->transitionTo($task, 'in_progress');
    }

    public function test_status_transitions_write_one_history_row_and_allow_reopening_done_tasks(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');

        $lifecycle = app(TaskLifecycleService::class);
        $lifecycle->transitionTo($task, 'in_progress');
        $lifecycle->transitionTo($task, 'review', 'Ready for review');
        $lifecycle->transitionTo($task, 'done');
        $lifecycle->transitionTo($task, 'todo', 'Reopened');
        $lifecycle->transitionTo($task, 'todo');

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(4, TaskStatusHistory::query()->count());
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->getKey(),
            'changed_by_user_id' => $admin->getKey(),
            'old_status' => 'done',
            'new_status' => 'todo',
            'reason' => 'Reopened',
        ]);
    }

    public function test_archiving_and_restoring_normalize_markers_and_preserve_related_records(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $comment = TaskComment::query()->create([
            'task_id' => $task->getKey(), 'user_id' => $admin->getKey(), 'comment_text' => 'Keep this comment',
        ]);
        $related = $this->makeTaskRelation($task, $project, $admin);
        $this->actingAs($admin, 'web');

        $lifecycle = app(TaskLifecycleService::class);
        $lifecycle->archive($task);
        $archived = $task->fresh();
        $this->assertSame('archived', $archived->status);
        $this->assertTrue($archived->is_archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->getKey(), 'old_status' => 'todo', 'new_status' => 'archived',
        ]);

        $lifecycle->restore($archived);
        $restored = $task->fresh();
        $this->assertSame('todo', $restored->status);
        $this->assertFalse($restored->is_archived);
        $this->assertNull($restored->archived_at);
        $this->assertDatabaseHas('task_comments', ['id' => $comment->getKey()]);
        $this->assertDatabaseHas('task_relations', ['id' => $related->getKey()]);
        $this->assertSame(2, TaskStatusHistory::query()->count());
    }

    public function test_inconsistent_archive_markers_are_normalized_by_restore_without_duplicate_history(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin, [
            'status' => 'done', 'is_archived' => true, 'archived_at' => null,
        ]);
        $this->actingAs($admin, 'web');

        app(TaskLifecycleService::class)->restore($task);

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertFalse($task->fresh()->is_archived);
        $this->assertNull($task->fresh()->archived_at);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->getKey(), 'old_status' => 'done', 'new_status' => 'todo',
        ]);
    }

    public function test_history_failure_rolls_back_the_task_status_change(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');
        TaskStatusHistory::creating(function (): never {
            throw new RuntimeException('Test-only history failure.');
        });

        try {
            app(TaskLifecycleService::class)->transitionTo($task, 'in_progress');
            $this->fail('The simulated history failure should be raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test-only history failure.', $exception->getMessage());
        }

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(0, TaskStatusHistory::query()->count());
    }

    public function test_invalid_status_and_non_admin_transition_are_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $lifecycle = app(TaskLifecycleService::class);
        $this->actingAs($admin, 'web');

        try {
            $lifecycle->transitionTo($task, 'archived');
            $this->fail('Archived state must use the archive operation.');
        } catch (DomainException) {
            $this->assertSame('todo', $task->fresh()->status);
        }

        Auth::forgetGuards();
        $developer = $this->makeUser('developer');
        $this->actingAs($developer, 'web');

        $this->expectException(AuthorizationException::class);
        $lifecycle->transitionTo($task, 'in_progress');
    }

    public function test_rejected_lifecycle_action_notifies_without_writing_status_or_history(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $this->mock(TaskLifecycleService::class, function ($mock): void {
            $mock->shouldReceive('transitionTo')
                ->once()
                ->andThrow(new DomainException('The transition is invalid for the current state.'));
        });

        Livewire::test(ListTasks::class)
            ->callTableAction('changeStatus', $task, ['new_status' => 'in_progress'])
            ->assertNotified('Transição não realizada');

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(0, TaskStatusHistory::query()->count());
    }

    public function test_archived_task_cannot_use_an_operational_transition_to_bypass_restore(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin, [
            'status' => 'archived', 'is_archived' => false, 'archived_at' => null,
        ]);
        $this->actingAs($admin, 'web');

        $this->expectException(DomainException::class);
        app(TaskLifecycleService::class)->transitionTo($task, 'todo');
    }

    public function test_task_view_displays_read_only_comments_and_history_without_delete_actions(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        TaskComment::query()->create([
            'task_id' => $task->getKey(), 'user_id' => $admin->getKey(), 'comment_text' => 'Read-only comment',
        ]);
        $relation = $this->makeTaskRelation($task, $project, $admin);
        TaskStatusHistory::query()->create([
            'task_id' => $task->getKey(),
            'changed_by_user_id' => $admin->getKey(),
            'old_status' => 'todo',
            'new_status' => 'review',
            'reason' => 'Read-only history',
        ]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertSee('Read-only comment')
            ->assertSee('Read-only history')
            ->assertSee('Related task');

        $list = Livewire::test(ListTasks::class)
            ->assertTableActionDoesNotExist('delete', null, $task);
        $this->assertSame([], $list->instance()->getTable()->getBulkActions());
        $this->assertSame(1, TaskComment::query()->count());
        $this->assertSame(1, TaskStatusHistory::query()->count());
        $this->assertSame(1, TaskRelation::query()->count());
        $this->assertSame(0, $relation->targetTask->outgoingRelations()->count());
    }

    public function test_task_form_does_not_expose_protected_fields_for_editing(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $otherProject = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])
            ->assertFormFieldDisabled('project_id')
            ->assertFormFieldDoesNotExist('reporter_user_id')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('is_archived')
            ->assertFormFieldDoesNotExist('archived_at')
            ->fillForm([
                'title' => 'Safe edit',
                'project_id' => $otherProject->getKey(),
                'reporter_user_id' => $otherProject->owner_user_id,
                'status' => 'done',
                'is_archived' => true,
                'archived_at' => now(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->getKey(),
            'title' => 'Safe edit',
            'project_id' => $project->getKey(),
            'reporter_user_id' => $admin->getKey(),
            'status' => 'todo',
            'is_archived' => false,
            'archived_at' => null,
        ]);
    }

    public function test_task_list_has_no_delete_actions_and_hides_mutations_for_archived_projects(): void
    {
        $admin = $this->makeUser('admin');
        $activeProject = $this->makeProject($admin);
        $archivedProject = $this->makeProject($admin, [
            'status' => 'archived', 'is_archived' => true, 'archived_at' => now(),
        ]);
        $activeTask = $this->makeTask($activeProject, $admin);
        $archivedProjectTask = $this->makeTask($archivedProject, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $list = Livewire::test(ListTasks::class)
            ->assertTableActionExists('changeStatus', null, $activeTask)
            ->assertTableActionExists('archive', null, $activeTask)
            ->assertTableActionExists('changeStatus', fn ($action): bool => $action->isHidden(), $archivedProjectTask)
            ->assertTableActionExists('archive', fn ($action): bool => $action->isHidden(), $archivedProjectTask)
            ->assertTableActionDoesNotExist('delete', null, $activeTask);

        $this->assertSame([], $list->instance()->getTable()->getBulkActions());
        $this->assertNotNull(Task::query()->find($activeTask->getKey()));
    }

    public function test_task_resource_index_is_the_authorized_kanban_entry_point(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'web');

        $this->get('/admin/tasks')->assertOk()->assertSee('A fazer')->assertSee('Em andamento');
        $this->get('/admin/tasks/kanban')->assertNotFound();
        $this->get('/admin/tasks/create')->assertOk();

        Auth::forgetGuards();
        $developer = $this->makeUser('developer');
        $this->actingAs($developer, 'web');
        $this->get('/admin/tasks')->assertForbidden();
    }

    public function test_kanban_quick_create_sets_the_status_for_each_column_and_refreshes_the_board(): void
    {
        $admin = $this->makeUser('admin');
        $assignee = $this->makeUser('developer');
        $project = $this->makeProject($admin);
        $this->makeMembership($project, $assignee, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $board = Livewire::test(TaskKanban::class)
            ->assertSee('wire:click="mountAction(\'createTodo\')"', false)
            ->assertSee('wire:click="mountAction(\'createInProgress\')"', false)
            ->assertSee('wire:click="mountAction(\'createReview\')"', false)
            ->assertSee('wire:click="mountAction(\'createDone\')"', false);

        foreach ([
            ['createTodo', 'A fazer card', 'todo'],
            ['createInProgress', 'In progress card', 'in_progress'],
            ['createReview', 'Review card', 'review'],
            ['createDone', 'Done card', 'done'],
        ] as [$action, $title, $status]) {
            $board->callAction($action, [
                'title' => $title,
                'description' => 'Created from quick action',
                'project_id' => $project->getKey(),
                'task_type' => 'feature',
                'priority' => 'medium',
                'assigned_user_id' => $assignee->getKey(),
                'reporter_user_id' => $this->makeUser('user')->getKey(),
                'status' => 'archived',
                'is_archived' => true,
                'archived_at' => now()->toDateTimeString(),
            ])->assertSee($title);

            $task = Task::query()->where('title', $title)->sole();
            $this->assertSame($status, $task->status);
            $this->assertSame($admin->getKey(), $task->reporter_user_id);
            $this->assertSame($assignee->getKey(), $task->assigned_user_id);
            $this->assertFalse($task->is_archived);
            $this->assertNull($task->archived_at);
        }

        $this->assertSame(4, Task::query()->count());
    }

    public function test_quick_create_rejects_paused_or_archived_projects_and_ineligible_assignees(): void
    {
        $admin = $this->makeUser('admin');
        $pausedProject = $this->makeProject($admin, ['status' => 'paused']);
        $archivedProject = $this->makeProject($admin, [
            'status' => 'archived', 'is_archived' => true, 'archived_at' => now(),
        ]);
        $activeProject = $this->makeProject($admin);
        $unassignedUser = $this->makeUser('developer');
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        foreach ([$pausedProject, $archivedProject] as $project) {
            try {
                app(TaskCreationService::class)->create([
                    'title' => 'Must not be created',
                    'project_id' => $project->getKey(),
                    'task_type' => 'feature',
                    'priority' => 'medium',
                ]);
                $this->fail('Inactive projects must be rejected by the creation service.');
            } catch (ValidationException) {
                $this->assertSame(0, Task::query()->count());
            }
        }

        try {
            app(TaskCreationService::class)->create([
                'title' => 'Invalid assignee task',
                'project_id' => $activeProject->getKey(),
                'task_type' => 'feature',
                'priority' => 'medium',
                'assigned_user_id' => $unassignedUser->getKey(),
            ]);
            $this->fail('An ineligible assignee must be rejected by the creation service.');
        } catch (ValidationException) {
            $this->assertSame(0, Task::query()->count());
        }

        $this->assertSame(0, Task::query()->count());
    }

    public function test_quick_create_shows_a_notification_when_server_side_eligibility_changes(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $this->mock(TaskAssignmentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('projectOptionsForTaskCreation')->andReturn([$project->getKey() => $project->name]);
            $mock->shouldReceive('optionsForProject')->andReturn([]);
            $mock->shouldReceive('canCreateInProject')->once()->andReturn(false);
        });

        Livewire::test(TaskKanban::class)
            ->callAction('createTodo', [
                'title' => 'Rejected by current project state',
                'project_id' => $project->getKey(),
                'task_type' => 'feature',
                'priority' => 'medium',
            ])
            ->assertNotified('Tarefa não criada');

        $this->assertSame(0, Task::query()->count());
    }

    public function test_kanban_shows_only_operational_tasks_and_filters_by_project_priority_and_assignee(): void
    {
        $admin = $this->makeUser('admin');
        $assignee = $this->makeUser('developer');
        $project = $this->makeProject($admin);
        $otherProject = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin, [
            'title' => 'Visible kanban card',
            'priority' => 'urgent',
            'assigned_user_id' => $assignee->getKey(),
        ]);
        $this->makeTask($project, $admin, ['title' => 'Archived card', 'status' => 'archived']);
        $other = $this->makeTask($otherProject, $admin, ['title' => 'Other project card']);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $board = Livewire::test(TaskKanban::class)
            ->assertSee('A fazer')
            ->assertSee('Em andamento')
            ->assertSee('Em revisão')
            ->assertSee('Concluído')
            ->assertSee('Visible kanban card')
            ->assertDontSee('Archived card')
            ->assertSee('Other project card')
            ->set('projectFilter', (string) $project->getKey())
            ->assertSee('Visible kanban card')
            ->assertDontSee('Other project card')
            ->set('priorityFilter', 'urgent')
            ->set('assigneeFilter', (string) $assignee->getKey())
            ->assertSee('Visible kanban card');

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame('todo', $other->fresh()->status);
    }

    public function test_kanban_move_uses_lifecycle_service_and_writes_status_history(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin, ['title' => 'Move through kanban']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(TaskKanban::class)
            ->call('statusChanged', $task->getKey(), 'in_progress', [], []);

        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->getKey(),
            'old_status' => 'todo',
            'new_status' => 'in_progress',
            'changed_by_user_id' => $admin->getKey(),
        ]);
    }

    public function test_kanban_rejects_archived_tasks_and_archived_projects_without_history(): void
    {
        $admin = $this->makeUser('admin');
        $activeProject = $this->makeProject($admin);
        $archivedProject = $this->makeProject($admin, [
            'status' => 'archived', 'is_archived' => true, 'archived_at' => now(),
        ]);
        $archivedTask = $this->makeTask($activeProject, $admin, [
            'title' => 'Inconsistent archived task', 'status' => 'todo', 'is_archived' => true,
        ]);
        $projectTask = $this->makeTask($archivedProject, $admin, ['title' => 'Archived project task']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(TaskKanban::class)
            ->assertSee('Archived project task')
            ->assertSee('data-locked="true"', false)
            ->call('statusChanged', $archivedTask->getKey(), 'done', [], [])
            ->call('statusChanged', $projectTask->getKey(), 'done', [], []);

        $this->assertTrue($archivedTask->fresh()->isArchived());
        $this->assertSame('todo', $projectTask->fresh()->status);
        $this->assertSame(0, TaskStatusHistory::query()->count());
    }

    public function test_kanban_keeps_tasks_in_paused_projects_operational(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, ['status' => 'paused']);
        $task = $this->makeTask($project, $admin, ['title' => 'Paused project existing task']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(TaskKanban::class)
            ->assertSee('Paused project existing task')
            ->call('statusChanged', $task->getKey(), 'review', [], []);

        $this->assertSame('review', $task->fresh()->status);
        $this->assertSame(1, TaskStatusHistory::query()->count());
    }

    public function test_kanban_history_failure_rolls_back_move(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        TaskStatusHistory::creating(function (): never {
            throw new RuntimeException('Test-only Kanban history failure.');
        });

        try {
            Livewire::test(TaskKanban::class)->call('statusChanged', $task->getKey(), 'review', [], []);
            $this->fail('The history failure must abort the Kanban move.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test-only Kanban history failure.', $exception->getMessage());
        }

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(0, TaskStatusHistory::query()->count());
    }

    public function test_kanban_reports_domain_rejection_without_success_or_history(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $task = $this->makeTask($project, $admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $this->mock(TaskLifecycleService::class, function ($mock): void {
            $mock->shouldReceive('transitionTo')
                ->once()
                ->andThrow(new DomainException('Rejected by current domain state.'));
        });

        Livewire::test(TaskKanban::class)
            ->call('statusChanged', $task->getKey(), 'review', [], [])
            ->assertNotified('Movimentação não realizada');

        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(0, TaskStatusHistory::query()->count());
    }

    public function test_deadline_creation_editing_validation_and_details(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $input = ['project_id' => $project->id, 'title' => 'Com prazo', 'task_type' => 'feature', 'priority' => 'medium'];
        $task = app(TaskCreationService::class)->create($input + ['due_date' => '2026-10-25']);
        $this->assertNull($task->start_date);
        $this->assertSame('2026-10-25', $task->due_date->toDateString());
        $legacy = app(TaskCreationService::class)->create($input);
        $this->assertNull($legacy->due_date);
        $this->assertSame('Sem prazo', $legacy->deadline_status);
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->assertFormFieldDoesNotExist('completed_at')
            ->fillForm(['start_date' => '2026-10-20', 'due_date' => '2026-10-19'])
            ->call('save')->assertHasFormErrors(['due_date'])
            ->fillForm(['start_date' => '2026-10-20', 'due_date' => '2026-10-26'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('2026-10-26', $task->fresh()->due_date->toDateString());
        Livewire::test(ViewTask::class, ['record' => $task->id])->assertSee('Prazos e acompanhamento')->assertSee('26/10/2026');
        Livewire::test(CreateTask::class)->fillForm($input + ['due_date' => '2026-10-27'])
            ->call('create')->assertHasNoFormErrors();
        try {
            app(TaskCreationService::class)->create($input + ['start_date' => '2026-10-20', 'due_date' => '2026-10-19']);
            $this->fail('Invalid date range accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }
    }

    public function test_deadline_lifecycle_preserves_history_and_handles_legacy_and_archived_tasks(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 23:59:59'));
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        $task = $this->makeTask($project, $admin, ['due_date' => '2026-10-19']);
        $this->assertSame(0, $task->days_remaining);
        $this->assertSame('Vence hoje', $task->deadline_status);
        $service = app(TaskLifecycleService::class);
        $service->transitionTo($task, 'done');
        $this->assertSame('Concluída no prazo', $task->deadline_status);
        $completed = $task->completed_at->toDateTimeString();
        $this->travel(1)->days();
        $service->transitionTo($task, 'done');
        $this->assertSame($completed, $task->completed_at->toDateTimeString());
        $service->archive($task);
        $this->assertSame($completed, $task->completed_at->toDateTimeString());
        $this->assertSame('Concluída no prazo', $task->deadline_status);
        $service->restore($task);
        $this->assertNull($task->completed_at);
        $this->assertSame('Atrasada', $task->deadline_status);
        $this->assertSame(-1, $task->days_remaining);
        $service->transitionTo($task, 'done');
        $this->assertSame('Concluída com atraso', $task->deadline_status);
        $service->transitionTo($task, 'review');
        $this->assertNull($task->completed_at);
        $this->assertSame(5, $task->statusHistories()->count());
        $legacy = $this->makeTask($project, $admin, ['status' => 'done', 'due_date' => '2026-10-19']);
        $service->transitionTo($legacy, 'done');
        $this->assertNull($legacy->completed_at);
        $this->assertSame('Conclusão sem data conhecida', $legacy->deadline_status);
        $this->travelBack();
    }

    public function test_deadline_filters_sorting_and_kanban(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $late = $this->makeTask($project, $admin, ['title' => 'Late card', 'due_date' => '2026-10-18']);
        $soon = $this->makeTask($project, $admin, ['title' => 'Soon card', 'due_date' => '2026-10-26']);
        $far = $this->makeTask($project, $admin, ['title' => 'Far card', 'due_date' => '2026-10-27']);
        $none = $this->makeTask($project, $admin, ['title' => 'No deadline card']);
        $done = $this->makeTask($project, $admin, ['status' => 'done', 'due_date' => '2026-10-18']);
        $archived = $this->makeTask($project, $admin, ['status' => 'archived', 'due_date' => '2026-10-18']);
        Livewire::test(ListTasks::class)->filterTable('deadline', 'overdue')
            ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$soon, $far, $none, $done, $archived])
            ->filterTable('deadline', 'soon')->assertCanSeeTableRecords([$soon])->assertCanNotSeeTableRecords([$late, $far, $none, $done])
            ->filterTable('deadline', 'none')->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$late, $soon]);
        Livewire::test(ListTasks::class)->filterTable('status', 'todo')->sortTable('due_date', 'desc')
            ->assertCanSeeTableRecords([$far, $soon, $late, $none], inOrder: true);
        Livewire::test(TaskKanban::class)->set('deadlineFilter', 'overdue')
            ->assertSee('Late card')->assertDontSee('Soon card')
            ->set('deadlineFilter', 'soon')->assertSee('Soon card')->assertDontSee('Late card');
        $this->travelBack();
    }

    public function test_deadlines_use_application_timezone_and_completion_is_protected(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->travelTo(Carbon::parse('2026-10-20 01:00:00', 'UTC'));
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $task = $this->makeTask($project, $admin, ['due_date' => '2026-10-20']);
        $this->assertSame(1, $task->days_remaining);
        $this->assertSame('No prazo', $task->deadline_status);
        $task->completed_at = Carbon::parse('2026-10-21 01:00:00', 'UTC');
        $task->status = 'done';
        $this->assertSame('Concluída no prazo', $task->deadline_status);
        $created = app(TaskCreationService::class)->create([
            'project_id' => $project->id, 'title' => 'Initial done', 'task_type' => 'feature', 'priority' => 'medium',
            'due_date' => '2026-10-20', 'completed_at' => '2000-01-01 00:00:00',
        ], 'done');
        $this->assertTrue($created->completed_at->equalTo(now()));
        Livewire::test(EditTask::class, ['record' => $created->id])
            ->fillForm(['title' => 'Still done', 'completed_at' => '2000-01-01 00:00:00'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($created->fresh()->completed_at->equalTo(now()));
        $this->travelBack();
    }

    public function test_deadline_migration_preserves_existing_tasks_and_can_be_reversed_in_isolation(): void
    {
        $admin = $this->makeUser('admin');
        $task = $this->makeTask($this->makeProject($admin), $admin, ['status' => 'done']);
        $migration = require database_path('migrations/2026_10_19_000001_add_task_deadlines.php');
        $migration->down();
        $before = (array) DB::table('tasks')->find($task->id);
        $migration->up();
        $after = (array) DB::table('tasks')->find($task->id);
        $this->assertSame($before, Arr::except($after, ['start_date', 'due_date', 'completed_at']));
        $this->assertNull($after['completed_at']);
        $this->assertNull($after['due_date']);
        $this->assertNull($after['start_date']);
    }

    public function test_markdown_rendering_is_safe_and_supports_structured_content(): void
    {
        $markdown = "# Título\n\n## Objetivo\n\n### Entregas\n\n**Forte** *ênfase* [link](https://example.com)\nquebra\n\n- Lista\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n```php\necho 'ok';\n```\n\n- [ ] Pendente\n- [x] Pronto\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[ruim](javascript:alert(1))";
        $html = app(TaskMarkdown::class)->render($markdown);
        foreach (['<h1>', '<h2>', '<h3>', '<strong>', '<em>', '<ul>', '<table>', 'language-php', '<br>', 'type="checkbox"', 'checked=""', 'disabled=""'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        foreach (['<script', '<img', 'javascript:', 'onerror'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }

    public function test_checklist_persists_and_stale_updates_are_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $description = "# Antiga\r\n\r\n- [ ] Primeiro\r\n- [x] Segundo\r\n\r\n```md\r\n- [ ] Exemplo\r\n```";
        $task = $this->makeTask($this->makeProject($admin), $admin, ['description' => $description]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $view = Livewire::test(ViewTask::class, ['record' => $task->id])
            ->assertSee('wire:change', false)
            ->call('toggleChecklist', 3, hash('sha256', $description), true);
        $updated = str_replace('[ ] Primeiro', '[x] Primeiro', $description);
        $this->assertSame($updated, $task->fresh()->description);
        $view->call('toggleChecklist', 4, hash('sha256', $description), false)
            ->assertNotified('A descrição foi alterada. Confira a versão atual e tente novamente.');
        $this->assertSame($updated, $task->fresh()->description);
        Livewire::test(ViewTask::class, ['record' => $task->id])
            ->call('toggleChecklist', 3, hash('sha256', $updated), false);
        $this->assertSame($description, $task->fresh()->description);
    }

    public function test_checklist_cannot_modify_code_examples(): void
    {
        $this->expectException(ValidationException::class);
        app(TaskMarkdown::class)->toggle("```md\n- [ ] Exemplo\n```", 2, true);
    }

    public function test_checklist_reauthorizes_every_request(): void
    {
        $admin = $this->makeUser('admin');
        $task = $this->makeTask($this->makeProject($admin), $admin, ['description' => '- [ ] Item']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $view = Livewire::test(ViewTask::class, ['record' => $task->id]);
        $task->project->update(['status' => 'archived', 'is_archived' => true, 'archived_at' => now()]);
        $view->call('toggleChecklist', 1, hash('sha256', $task->description), true)->assertForbidden();
        $this->assertSame('- [ ] Item', $task->fresh()->description);
    }

    public function test_edit_preserves_legacy_description_and_changes_assignee(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $first = $this->makeUser('developer');
        $second = $this->makeUser('developer');
        $this->makeMembership($project, $first, $admin);
        $this->makeMembership($project, $second, $admin);
        $description = "Texto antigo\r\n  com espaços\r\n\n- [x] Item";
        $task = $this->makeTask($project, $admin, ['assigned_user_id' => $first->id, 'description' => $description]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $edit = Livewire::test(EditTask::class, ['record' => $task->id])
            ->assertFormSet(['assigned_user_id' => $first->id, 'description' => $description])
            ->fillForm(['title' => 'Novo título'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($first->id, $task->fresh()->assigned_user_id);
        $this->assertSame($description, $task->fresh()->description);
        $edit->fillForm(['assigned_user_id' => $second->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($second->id, $task->fresh()->assigned_user_id);
        Livewire::test(ViewTask::class, ['record' => $task->id])->assertSee($second->name);
    }

    public function test_stale_edit_cannot_overwrite_new_description(): void
    {
        $admin = $this->makeUser('admin');
        $task = $this->makeTask($this->makeProject($admin), $admin, ['description' => '- [ ] Item']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $edit = Livewire::test(EditTask::class, ['record' => $task->id]);
        $task->update(['description' => '- [x] Item']);
        $edit->fillForm(['description' => 'Versão antiga'])->call('save')->assertHasFormErrors(['description']);
        $this->assertSame('- [x] Item', $task->fresh()->description);
    }

    private function makeUser(string $roleSlug, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst(str_replace('-', ' ', $roleSlug))],
        );

        return User::query()->create([
            'role_id' => $role->getKey(),
            'name' => ucfirst($roleSlug),
            'email' => $roleSlug.'-'.$status.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password_hash' => 'test-only-password',
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProject(User $owner, array $overrides = []): Project
    {
        static $index = 0;
        $index++;

        return Project::query()->create(array_merge([
            'name' => 'Project '.$index,
            'slug' => 'project-'.$index.'-'.bin2hex(random_bytes(3)),
            'status' => 'active',
            'owner_user_id' => $owner->getKey(),
        ], $overrides));
    }

    private function makeMembership(Project $project, User $user, User $grantedBy, string $status = 'active'): ProjectMembership
    {
        return ProjectMembership::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $user->getKey(),
            'role_in_project' => 'contributor',
            'status' => $status,
            'granted_by_user_id' => $grantedBy->getKey(),
            'revoked_at' => $status === 'revoked' ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTask(Project $project, User $reporter, array $overrides = []): Task
    {
        $attributes = array_merge([
            'project_id' => $project->getKey(),
            'title' => 'Original task',
            'task_type' => 'feature',
            'priority' => 'medium',
            'status' => 'todo',
            'reporter_user_id' => $reporter->getKey(),
            'is_archived' => false,
        ], $overrides);

        $task = new Task(Arr::except($attributes, [
            'status',
            'reporter_user_id',
            'is_archived',
            'archived_at',
        ]));

        foreach (['status', 'reporter_user_id', 'is_archived', 'archived_at'] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $task->{$attribute} = $attributes[$attribute];
            }
        }

        $task->saveOrFail();

        return $task;
    }

    private function makeTaskRelation(Task $source, Project $project, User $reporter): TaskRelation
    {
        $target = $this->makeTask($project, $reporter, ['title' => 'Related task']);

        return TaskRelation::query()->create([
            'source_task_id' => $source->getKey(),
            'target_task_id' => $target->getKey(),
            'relation_type' => 'relates_to',
        ]);
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectResourceTest extends TestCase
{
    private static string $compiledViewsPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$compiledViewsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'spacedev-project-resource-views-'.bin2hex(random_bytes(6));
        mkdir(self::$compiledViewsPath, 0777, true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['view.compiled' => self::$compiledViewsPath]);

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('slug', 50)->unique();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('repository_url', 255)->nullable();
            $table->enum('status', ['active', 'archived', 'paused'])->default('active');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('env_type');
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'name']);
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory(self::$compiledViewsPath);

        parent::tearDownAfterClass();
    }

    public function test_active_admin_can_open_and_render_the_project_list(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Visible project', 'visible-project');

        $this->actingAs($admin, 'web');

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('Visible project')
            ->assertSee('Ativo');
    }

    public function test_active_non_admin_cannot_open_the_project_resource(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'web')
            ->get('/admin/projects')
            ->assertForbidden();
    }

    public function test_create_form_validates_required_fields_and_creates_using_database_defaults(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => '',
                'slug' => '',
                'owner_user_id' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'slug' => 'required',
                'owner_user_id' => 'required',
            ]);

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Created project',
                'slug' => 'created-project',
                'description' => 'A project created in the in-memory test database.',
                'repository_url' => 'https://example.test/repository',
                'owner_user_id' => $admin->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Created project',
            'slug' => 'created-project',
            'owner_user_id' => $admin->getKey(),
            'status' => 'active',
            'is_archived' => false,
            'archived_at' => null,
        ]);
    }

    public function test_edit_form_updates_editable_fields_without_changing_status_or_archive_markers(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Before edit', 'before-edit', [
            'status' => 'paused',
            'is_archived' => true,
            'archived_at' => '2026-01-02 03:04:05',
        ]);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'name' => 'After edit',
                'slug' => 'after-edit',
                'owner_user_id' => $admin->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->getKey(),
            'name' => 'After edit',
            'slug' => 'after-edit',
            'status' => 'paused',
            'is_archived' => true,
            'archived_at' => '2026-01-02 03:04:05',
        ]);
    }

    public function test_project_view_shows_owner_and_project_details(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Viewable project', 'viewable-project');

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertSee('Viewable project')
            ->assertSee('Active Admin')
            ->assertSee('Ativo');
    }

    public function test_permanent_delete_action_is_not_available(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Delete confirmation project', 'delete-confirmation-project');

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ListProjects::class)
            ->assertTableActionDoesNotExist('delete', null, $project);
    }

    public function test_lifecycle_actions_are_visible_only_for_applicable_project_states_and_have_no_delete_actions(): void
    {
        $admin = $this->makeUser('admin');
        $active = $this->makeProject($admin, 'Active project', 'active-project');
        $paused = $this->makeProject($admin, 'Paused project', 'paused-project', ['status' => 'paused']);
        $archived = $this->makeProject($admin, 'Archived project', 'archived-project', [
            'status' => 'archived',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $table = Livewire::test(ListProjects::class)
            ->assertTableActionExists('pause', null, $active)
            ->assertTableActionExists('archive', fn ($action): bool => $action->isConfirmationRequired(), $active)
            ->assertTableActionExists('activate', fn ($action): bool => $action->isHidden(), $active)
            ->assertTableActionExists('restore', fn ($action): bool => $action->isHidden(), $active)
            ->assertTableActionExists('activate', null, $paused)
            ->assertTableActionExists('archive', null, $paused)
            ->assertTableActionExists('pause', fn ($action): bool => $action->isHidden(), $paused)
            ->assertTableActionExists('restore', fn ($action): bool => $action->isHidden(), $paused)
            ->assertTableActionExists('restore', fn ($action): bool => $action->isConfirmationRequired(), $archived)
            ->assertTableActionExists('pause', fn ($action): bool => $action->isHidden(), $archived)
            ->assertTableActionExists('activate', fn ($action): bool => $action->isHidden(), $archived)
            ->assertTableActionExists('archive', fn ($action): bool => $action->isHidden(), $archived)
            ->assertTableActionDoesNotExist('delete', null, $active);

        $this->assertSame([], $table->instance()->getTable()->getBulkActions());
    }

    public function test_lifecycle_transitions_update_all_markers_and_reject_invalid_moves(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Lifecycle project', 'lifecycle-project');

        $project->pause();
        $this->assertSame('paused', $project->fresh()->status);
        $this->assertFalse($project->fresh()->is_archived);
        $this->assertNull($project->fresh()->archived_at);

        $project->activate();
        $this->assertSame('active', $project->fresh()->status);
        $this->assertFalse($project->fresh()->is_archived);
        $this->assertNull($project->fresh()->archived_at);

        DB::table('project_environments')->insert([
            'project_id' => $project->getKey(),
            'name' => 'Production',
            'env_type' => 'production',
            'is_active' => true,
            'created_at' => now(),
        ]);

        $archiveStartedAt = now()->subSecond();
        $project->archive();
        $archived = $project->fresh();
        $this->assertSame('archived', $archived->status);
        $this->assertTrue($archived->is_archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertTrue($archived->archived_at->greaterThanOrEqualTo($archiveStartedAt));
        $this->assertTrue($archived->archived_at->lessThanOrEqualTo(now()->addSecond()));
        $this->assertDatabaseHas('project_environments', [
            'project_id' => $project->getKey(),
            'name' => 'Production',
        ]);

        $project->restore();
        $restored = $project->fresh();
        $this->assertSame('active', $restored->status);
        $this->assertFalse($restored->is_archived);
        $this->assertNull($restored->archived_at);
        $this->assertDatabaseHas('project_environments', [
            'project_id' => $project->getKey(),
            'name' => 'Production',
        ]);

        $this->expectException(\DomainException::class);
        $project->restore();
    }

    public function test_archive_markers_are_interpreted_conservatively_for_inconsistent_existing_states(): void
    {
        $admin = $this->makeUser('admin');
        $inconsistent = $this->makeProject($admin, 'Inconsistent project', 'inconsistent-project', [
            'status' => 'active',
            'is_archived' => true,
            'archived_at' => null,
        ]);

        $this->assertTrue($inconsistent->isArchived());
        $this->assertTrue($inconsistent->canBeRestored());
        $this->assertFalse($inconsistent->canBePaused());
        $this->assertFalse($inconsistent->canBeActivated());
        $this->assertFalse($inconsistent->canBeArchived());

        $inconsistent->restore();

        $this->assertSame('active', $inconsistent->fresh()->status);
        $this->assertFalse($inconsistent->fresh()->is_archived);
        $this->assertNull($inconsistent->fresh()->archived_at);
    }

    public function test_invalid_lifecycle_transitions_are_rejected_without_changing_state(): void
    {
        $admin = $this->makeUser('admin');
        $active = $this->makeProject($admin, 'Active invalid transition', 'active-invalid-transition');
        $paused = $this->makeProject($admin, 'Paused invalid transition', 'paused-invalid-transition', ['status' => 'paused']);
        $inconsistent = $this->makeProject($admin, 'Archived invalid transition', 'archived-invalid-transition', [
            'status' => 'active',
            'is_archived' => true,
        ]);

        $invalidTransitions = [
            fn () => $active->activate(),
            fn () => $paused->pause(),
            fn () => $inconsistent->archive(),
            fn () => $active->restore(),
        ];

        foreach ($invalidTransitions as $transition) {
            try {
                $transition();
                $this->fail('An invalid project lifecycle transition was accepted.');
            } catch (\DomainException) {
                // The domain method rejected the invalid transition as expected.
            }
        }

        $this->assertDatabaseHas('projects', [
            'id' => $active->getKey(),
            'status' => 'active',
            'is_archived' => false,
            'archived_at' => null,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $paused->getKey(),
            'status' => 'paused',
            'is_archived' => false,
            'archived_at' => null,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $inconsistent->getKey(),
            'status' => 'active',
            'is_archived' => true,
            'archived_at' => null,
        ]);
    }

    public function test_archived_projects_remain_listed_and_editable_without_lifecycle_field_inputs(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin, 'Preserved archive', 'preserved-archive', [
            'status' => 'archived',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('Preserved archive')
            ->assertSee('Arquivado');

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('is_archived')
            ->assertFormFieldDoesNotExist('archived_at')
            ->fillForm([
                'name' => 'Still archived',
                'slug' => 'still-archived',
                'owner_user_id' => $admin->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->getKey(),
            'name' => 'Still archived',
            'status' => 'archived',
            'is_archived' => true,
            'archived_at' => $project->archived_at->toDateTimeString(),
        ]);
    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst(str_replace('-', ' ', $roleSlug))],
        );

        return User::query()->create([
            'role_id' => $role->getKey(),
            'name' => $roleSlug === 'admin' ? 'Active Admin' : ucfirst($roleSlug),
            'email' => $roleSlug.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password_hash' => 'test-only-password',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProject(User $owner, string $name, string $slug, array $overrides = []): Project
    {
        return Project::query()->create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'owner_user_id' => $owner->getKey(),
        ], $overrides));
    }
}

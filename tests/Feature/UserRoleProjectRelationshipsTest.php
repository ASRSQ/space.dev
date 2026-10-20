<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UserRoleProjectRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->nullable();
            $table->string('name', 150);
            $table->string('email', 191);
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status');
            $table->foreignId('owner_user_id');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
        });
    }

    public function test_role_user_and_project_owner_relationships_use_the_schema_keys(): void
    {
        $role = Role::create(['name' => 'Member', 'slug' => 'member']);
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password_hash' => 'plain-text-password',
            'status' => 'active',
        ]);
        $project = Project::create([
            'name' => 'Test Project',
            'slug' => 'test-project',
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        $this->assertSame($user->id, $role->users()->sole()->id);
        $this->assertSame($role->id, $user->role->id);
        $this->assertSame($project->id, $user->ownedProjects()->sole()->id);
        $this->assertSame($user->id, $project->owner->id);
        $this->assertSame('owner_user_id', $project->owner()->getForeignKeyName());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $project->tasks());
    }

    public function test_user_password_hash_is_used_for_authentication_and_hidden_from_serialization(): void
    {
        $user = new User(['password_hash' => 'plain-text-password']);

        $this->assertSame('password_hash', $user->getAuthPasswordName());
        $this->assertNotSame('plain-text-password', $user->password_hash);
        $this->assertTrue(password_verify('plain-text-password', $user->password_hash));
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function test_auth_provider_accepts_correct_and_rejects_incorrect_password_hash_credentials(): void
    {
        Auth::forgetGuards();
        User::create([
            'name' => 'Active User',
            'email' => 'active@example.test',
            'password_hash' => 'correct-password',
            'status' => 'active',
        ]);

        $this->assertSame('active-eloquent', config('auth.providers.users.driver'));
        $this->assertTrue(Auth::guard('web')->attempt([
            'email' => 'active@example.test',
            'password' => 'correct-password',
        ]));

        Auth::forgetGuards();
        $this->assertFalse(Auth::guard('web')->attempt([
            'email' => 'active@example.test',
            'password' => 'incorrect-password',
        ]));
    }

    public function test_auth_provider_rejects_inactive_users_for_password_and_session_retrieval(): void
    {
        Auth::forgetGuards();
        $user = User::create([
            'name' => 'Suspended User',
            'email' => 'suspended@example.test',
            'password_hash' => 'correct-password',
            'status' => 'suspended',
        ]);

        $guard = Auth::guard('web');
        $this->assertFalse($guard->attempt([
            'email' => 'suspended@example.test',
            'password' => 'correct-password',
        ]));
        $this->assertNull($guard->getProvider()->retrieveById($user->id));
    }

    public function test_role_tracks_created_at_without_an_updated_at_column(): void
    {
        $role = Role::create(['name' => 'Member', 'slug' => 'member']);

        $this->assertTrue($role->usesTimestamps());
        $this->assertSame('created_at', $role->getCreatedAtColumn());
        $this->assertNull($role->getUpdatedAtColumn());
        $this->assertNotNull($role->created_at);
    }
}

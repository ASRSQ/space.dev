<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPanelAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_only_active_admin_roles_can_access_the_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        foreach ([
            'super-admin' => true,
            'admin' => true,
            'developer' => false,
            'user' => false,
            'viewer' => false,
        ] as $slug => $canAccess) {
            $user = $this->makeUser($slug, 'active');

            $this->assertSame($canAccess, $user->canAccessPanel($panel), "Unexpected panel access for role [{$slug}].");
        }
    }

    public function test_inactive_and_suspended_administrators_cannot_access_the_panel(): void
    {
        $panel = Filament::getPanel('admin');

        foreach (['super-admin', 'admin'] as $slug) {
            foreach (['inactive', 'suspended'] as $status) {
                $user = $this->makeUser($slug, $status);

                $this->assertFalse($user->canAccessPanel($panel), "Unexpected access for [{$slug}] with status [{$status}].");
            }
        }
    }

    public function test_authorization_is_restricted_to_the_admin_panel_identifier(): void
    {
        $user = $this->makeUser('admin', 'active');

        $this->assertFalse($user->canAccessPanel(Panel::make()->id('other')));
    }

    public function test_active_admin_can_log_in_through_filament_and_open_the_panel(): void
    {
        $password = 'test-only-password';
        $user = $this->makeUser('admin', 'active', $password);

        $this->filamentLogin()
            ->fillForm([
                'email' => $user->email,
                'password' => $password,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/admin')->assertOk();
    }

    public function test_active_super_admin_can_log_in_through_filament_and_open_the_panel(): void
    {
        $password = 'test-only-password';
        $user = $this->makeUser('super-admin', 'active', $password);

        $this->filamentLogin()
            ->fillForm([
                'email' => $user->email,
                'password' => $password,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/admin')->assertOk();
    }

    public function test_active_non_admin_roles_are_rejected_by_filament_login_and_panel(): void
    {
        foreach (['developer', 'user', 'viewer', 'programmer'] as $slug) {
            $password = 'test-only-password';
            $user = $this->makeUser($slug, 'active', $password);

            $this->filamentLogin()
                ->fillForm([
                    'email' => $user->email,
                    'password' => $password,
                ])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);

            $this->assertGuest('web');
            $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
            Auth::forgetGuards();
        }
    }

    public function test_inactive_and_suspended_users_are_rejected_by_filament_login(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $password = 'test-only-password';
            $user = $this->makeUser('admin', $status, $password);

            $this->filamentLogin()
                ->fillForm([
                    'email' => $user->email,
                    'password' => $password,
                ])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);

            $this->assertGuest('web');
            $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
            Auth::forgetGuards();
        }
    }

    public function test_filament_login_rejects_an_incorrect_password(): void
    {
        $user = $this->makeUser('admin', 'active', 'correct-test-only-password');

        $this->filamentLogin()
            ->fillForm([
                'email' => $user->email,
                'password' => 'incorrect-test-only-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_a_session_is_rejected_after_the_user_becomes_suspended(): void
    {
        $user = $this->makeUser('admin', 'active');
        Auth::guard('web')->login($user);

        $this->get('/admin')->assertOk();

        $user->status = 'suspended';
        $user->save();

        Auth::forgetGuards();

        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_role_seeder_is_idempotent_and_contains_only_the_five_official_roles(): void
    {
        $seeder = app(\Database\Seeders\RoleSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(5, Role::query()->count());
        $this->assertSame([
            'admin',
            'developer',
            'super-admin',
            'user',
            'viewer',
        ], Role::query()->orderBy('slug')->pluck('slug')->all());
    }

    private function filamentLogin(): mixed
    {
        Filament::setCurrentPanel('admin');

        return Livewire::test(Login::class);
    }

    private function makeUser(string $roleSlug, string $status, string $password = 'test-only-password'): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst(str_replace('-', ' ', $roleSlug))],
        );

        return User::query()->create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => $roleSlug.'-'.$status.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password_hash' => $password,
            'status' => $status,
        ]);
    }
}

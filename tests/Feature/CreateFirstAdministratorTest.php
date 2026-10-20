<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class CreateFirstAdministratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->unique();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });
    }

    private function createAdmin(string $email = 'bootstrap@example.test'): PendingCommand
    {
        return $this->artisan('space:create-admin')
            ->expectsQuestion('Administrator name', 'Bootstrap administrator')
            ->expectsQuestion('Administrator email', $email)
            ->expectsQuestion('Password (at least 12 characters)', 'Temporary!Password123')
            ->expectsQuestion('Confirm password', 'Temporary!Password123');
    }

    public function test_first_administrator_has_hashed_password_and_repeat_is_refused(): void
    {
        $this->createAdmin()->assertSuccessful()->run();
        $user = User::query()->sole();
        $this->assertSame('super-admin', $user->role->slug);
        $this->assertTrue(Hash::check('Temporary!Password123', $user->password_hash));
        $this->createAdmin('another@example.test')->assertFailed()->run();
        $this->assertSame(1, User::query()->count());
    }

    public function test_noninteractive_bootstrap_is_refused(): void
    {
        $this->artisan('space:create-admin', ['--no-interaction' => true])->assertFailed();
        $this->assertSame(0, User::query()->count());
    }
}

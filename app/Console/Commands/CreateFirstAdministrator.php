<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateFirstAdministrator extends Command
{
    protected $signature = 'space:create-admin';

    protected $description = 'Create the first active administrator using interactive, hidden password input';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive input is required; no passwords in command arguments.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->ask('Administrator name'),
            'email' => $this->ask('Administrator email'),
            'password' => $this->secret('Password (at least 12 characters)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        return DB::transaction(function () use ($data): int {
            // Serialize concurrent bootstrap attempts on the unique administrator role.
            Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrador']);
            $role = Role::query()->where('slug', 'super-admin')->lockForUpdate()->firstOrFail();
            if (User::query()->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'super-admin']))->lockForUpdate()->first() !== null) {
                $this->error('An active administrator already exists. Use application user management.');

                return self::FAILURE;
            }
            User::query()->create([
                'role_id' => $role->getKey(),
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => $data['password'],
                'status' => 'active',
            ]);
            $this->info('Administrator created.');

            return self::SUCCESS;
        });
    }
}

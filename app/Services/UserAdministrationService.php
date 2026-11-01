<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function save(array $input, ?User $record = null): User
    {
        return DB::transaction(function () use ($input, $record): User {
            Role::query()->whereIn('slug', ['admin', 'super-admin'])->orderBy('id')->lockForUpdate()->get();
            $record = $record ? User::query()->lockForUpdate()->findOrFail($record->id) : null;
            Gate::authorize($record ? 'update' : 'create', $record ?? User::class);
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($record?->id)],
                'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
                'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
                'password' => [$record ? 'nullable' : 'required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            ])->validate();
            $role = Role::findOrFail($data['role_id']);
            if ($role->slug === 'super-admin' && auth()->user()->role?->slug !== 'super-admin') {
                throw ValidationException::withMessages(['data.role_id' => 'Somente um super administrador pode atribuir esse papel.']);
            }
            if ($record?->is(auth()->user()) && ((int) $data['role_id'] !== $record->role_id || $data['status'] !== 'active')) {
                throw ValidationException::withMessages(['data.role_id' => 'Você não pode alterar o próprio papel nem desativar sua conta.']);
            }
            if ($record && $record->status === 'active' && in_array($record->role?->slug, ['admin', 'super-admin'], true)
                && ($data['status'] !== 'active' || ! in_array($role->slug, ['admin', 'super-admin'], true))
                && ! User::query()->where('status', 'active')->whereKeyNot($record->id)->whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'super-admin']))->exists()) {
                throw ValidationException::withMessages(['data.status' => 'Mantenha pelo menos um administrador ativo.']);
            }
            $attributes = Arr::only($data, ['name', 'email', 'role_id', 'status']);
            if (filled($data['password'] ?? null)) {
                $attributes['password_hash'] = $data['password'];
            }
            $invalidateSessions = isset($attributes['password_hash']) || $data['status'] !== 'active' || ($record && (int) $data['role_id'] !== $record->role_id);
            if ($invalidateSessions) {
                $attributes['remember_token'] = null;
            }
            $user = $record ?? new User;
            // Force-fill only the explicit allowlist, never arbitrary input.
            $user->forceFill($attributes)->save();
            if ($record && $invalidateSessions && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            return $user;
        });
    }
}

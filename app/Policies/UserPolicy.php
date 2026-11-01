<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ProjectAccess;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user);
    }

    public function view(User $user, User $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $record): bool
    {
        return $this->viewAny($user) && ($record->role?->slug !== 'super-admin' || $user->role?->slug === 'super-admin');
    }

    public function delete(User $user, User $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

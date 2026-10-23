<?php

namespace App\Policies;

use App\Models\Problem;
use App\Models\User;

class ProblemPolicy
{
    private function isAdministrator(User $user): bool
    {
        return $user->status === 'active'
            && $user->role()->whereIn('slug', ['admin', 'super-admin'])->exists();
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, Problem $problem): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, Problem $problem): bool
    {
        return $this->isAdministrator($user)
            && ! $problem->is_archived && $problem->archived_at === null
            && ! $problem->project()->first()?->isArchived();
    }

    public function delete(User $user, Problem $problem): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

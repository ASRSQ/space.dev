<?php

namespace App\Policies;

use App\Models\Problem;
use App\Models\User;
use App\Services\ProjectAccess;

class ProblemPolicy
{
    private function isAdministrator(User $user): bool
    {
        return $user->status === 'active'
            && $user->role()->whereIn('slug', ['admin', 'super-admin'])->exists();
    }

    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->panel($user);
    }

    public function view(User $user, Problem $problem): bool
    {
        return app(ProjectAccess::class)->view($user, $problem->project);
    }

    public function create(User $user): bool
    {
        return (new TaskPolicy)->create($user);
    }

    public function update(User $user, Problem $problem): bool
    {
        return app(ProjectAccess::class)->contribute($user, $problem->project)
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

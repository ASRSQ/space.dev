<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccess;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->panel($user);
    }

    public function view(User $user, Project $project): bool
    {
        return app(ProjectAccess::class)->view($user, $project);
    }

    public function create(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user);
    }

    public function update(User $user, Project $project): bool
    {
        return app(ProjectAccess::class)->platform($user) || (app(ProjectAccess::class)->manage($user, $project) && ! $project->isArchived());
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project) && ! $project->isArchived();
    }

    public function archive(User $user, Project $project): bool
    {
        return app(ProjectAccess::class)->manage($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

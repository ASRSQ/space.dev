<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\ProjectAccess;

class ProjectMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->panel($user);
    }

    public function view(User $user, ProjectMembership $record): bool
    {
        return app(ProjectAccess::class)->view($user, $record->project);
    }

    public function create(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user) || app(ProjectAccess::class)->scope(Project::query(), $user)->get()->contains(fn ($p) => app(ProjectAccess::class)->manage($user, $p));
    }

    public function update(User $user, ProjectMembership $record): bool
    {
        return app(ProjectAccess::class)->manage($user, $record->project) && ! $record->project->isArchived();
    }

    public function delete(User $user, ProjectMembership $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

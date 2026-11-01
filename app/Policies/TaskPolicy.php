<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccess;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->panel($user);
    }

    public function view(User $user, Task $task): bool
    {
        return app(ProjectAccess::class)->view($user, $task->project);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user) || app(ProjectAccess::class)->scope(Project::query(), $user)->get()->contains(fn ($project) => app(ProjectAccess::class)->contribute($user, $project));
    }

    public function update(User $user, Task $task): bool
    {
        $access = app(ProjectAccess::class);

        return $access->contribute($user, $task->project)
            && ($access->manage($user, $task->project) || $task->assigned_user_id === $user->id || $task->reporter_user_id === $user->id);
    }

    public function assign(User $user, Task $task): bool
    {
        return app(ProjectAccess::class)->manage($user, $task->project) && app(ProjectAccess::class)->contribute($user, $task->project);
    }

    public function archive(User $user, Task $task): bool
    {
        return $this->assign($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isAdministrator(User $user): bool
    {
        return $user->status === 'active'
            && $user->role()
                ->whereIn('slug', ['admin', 'super-admin'])
                ->exists();
    }
}

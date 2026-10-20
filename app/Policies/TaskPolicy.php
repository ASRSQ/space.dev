<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->isAdministrator($user)
            && ! $task->project()->first()?->isArchived();
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

<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectDocumentationPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->isDocumentationAdministrator($user);
    }

    public function edit(User $user, Project $project): bool
    {
        return $this->isDocumentationAdministrator($user);
    }

    public function submit(User $user, Project $project): bool
    {
        return $this->isDocumentationAdministrator($user);
    }

    public function review(User $user, Project $project): bool
    {
        return $this->isDocumentationAdministrator($user);
    }

    private function isDocumentationAdministrator(User $user): bool
    {
        return $user->status === 'active'
            && in_array($user->role?->slug, ['admin', 'super-admin'], true);
    }
}

<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccess;

class ProjectDocumentationPolicy
{
    public function view(User $user, Project $project): bool
    {
        return app(ProjectAccess::class)->view($user, $project);
    }

    public function edit(User $user, Project $project): bool
    {
        return app(ProjectAccess::class)->contribute($user, $project, 'content');
    }

    public function submit(User $user, Project $project): bool
    {
        return $this->edit($user, $project);
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

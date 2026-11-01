<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccess;
use Illuminate\Database\Eloquent\Model;

abstract class ProjectContentPolicy
{
    protected bool $usesDocumentationReview = true;

    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->panel($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $record->project ? app(ProjectAccess::class)->view($user, $record->project) : app(ProjectAccess::class)->platform($user);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $project ? app(ProjectAccess::class)->contribute($user, $project, 'content') : app(ProjectAccess::class)->platform($user);
    }

    public function update(User $user, Model $record): bool
    {
        return ! $record->is_archived && ! $record->archived_at && $this->create($user, $record->project);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function archive(User $user, Model $record): bool
    {
        return false;
    }

    public function review(User $user, Model $record): bool
    {
        // Reuse the existing documentation approval boundary; never invent prompt approval.
        return $this->usesDocumentationReview && $record->project && (new ProjectDocumentationPolicy)->review($user, $record->project);
    }
}

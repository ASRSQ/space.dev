<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Task;
use App\Models\User;

class TaskAssignmentService
{
    public function optionsForEditing(?int $projectId, ?Task $task): array
    {
        $options = $this->optionsForProject($projectId);
        if ($task?->assigned_user_id && ! array_key_exists($task->assigned_user_id, $options)) {
            $user = $task->assignedUser;
            if ($user) {
                $options[$user->id] = "{$user->name} (#{$user->id}) — não elegível; atribuição mantida";
            }
        }

        return $options;
    }

    public function isEligible(?int $userId, ?int $projectId): bool
    {
        if ($userId === null || $projectId === null) {
            return false;
        }

        $userIsActive = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['id']) !== null;

        if (! $userIsActive) {
            return false;
        }

        return ProjectMembership::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /** @return array<int, string> */
    public function optionsForProject(?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        return User::query()
            ->where('status', 'active')
            ->whereHas('projectMemberships', fn ($query) => $query
                ->where('project_id', $projectId)
                ->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => "{$user->name} (#{$user->getKey()})",
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function projectOptionsForTaskCreation(): array
    {
        return Project::query()
            ->where('status', 'active')
            ->where('is_archived', false)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function canCreateInProject(?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        $project = Project::query()->lockForUpdate()->find($projectId);

        return $project !== null
            && $project->status === 'active'
            && ! $project->isArchived();
    }
}

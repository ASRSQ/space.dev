<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectMembershipService
{
    public function grant(int $projectId, int $userId, string $role): ProjectMembership
    {
        return DB::transaction(function () use ($projectId, $userId, $role): ProjectMembership {
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            Gate::authorize('manageMembers', $project);
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($user->status !== 'active' || ! array_key_exists($role, ProjectAccess::ROLES)
                || ($project->organization_id && ! app(ProjectAccess::class)->organizationMember($user, $project->organization_id))) {
                throw ValidationException::withMessages(['user_id' => 'Escolha um usuário ativo da organização do projeto e um papel válido.']);
            }
            $existing = $project->memberships()->where('user_id', $userId)->where('status', 'active')->lockForUpdate()->first();
            if ($existing) {
                if ($existing->role_in_project === $role) {
                    return $existing;
                }
                // Preserve the old grant instead of silently rewriting its role.
                $existing->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);
            }

            return ProjectMembership::query()->create(['project_id' => $projectId, 'user_id' => $userId, 'role_in_project' => $role, 'status' => 'active', 'granted_by_user_id' => auth()->id(), 'granted_at' => now()]);
        });
    }

    public function revoke(ProjectMembership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            $project = Project::query()->lockForUpdate()->findOrFail($membership->project_id);
            Gate::authorize('manageMembers', $project);
            $membership = ProjectMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->status === 'active') {
                $membership->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);
            }
        });
    }
}

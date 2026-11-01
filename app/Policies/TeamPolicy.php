<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Services\ProjectAccess;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return (new OrganizationPolicy)->viewAny($user);
    }

    public function view(User $user, Team $record): bool
    {
        return app(ProjectAccess::class)->platform($user) || app(ProjectAccess::class)->organizationMember($user, $record->organization_id);
    }

    public function create(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user) || ($user->status === 'active' && $user->role?->slug !== 'viewer' && $user->organizationMemberships()->where('status', 'active')->where('role', 'admin')->whereHas('organization', fn ($q) => $q->whereNull('archived_at'))->exists());
    }

    public function update(User $user, Team $record): bool
    {
        return app(ProjectAccess::class)->platform($user) || app(ProjectAccess::class)->organizationAdmin($user, $record->organization_id);
    }

    public function delete(User $user, Team $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

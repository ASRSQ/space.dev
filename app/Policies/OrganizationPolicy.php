<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\ProjectAccess;
use Illuminate\Support\Facades\Schema;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user) || ($user->status === 'active' && Schema::hasTable('organization_memberships') && $user->organizationMemberships()->where('status', 'active')->exists());
    }

    public function view(User $user, Organization $record): bool
    {
        return app(ProjectAccess::class)->platform($user) || app(ProjectAccess::class)->organizationMember($user, $record->id);
    }

    public function create(User $user): bool
    {
        return app(ProjectAccess::class)->platform($user);
    }

    public function update(User $user, Organization $record): bool
    {
        return app(ProjectAccess::class)->platform($user) || app(ProjectAccess::class)->organizationAdmin($user, $record->id);
    }

    public function delete(User $user, Organization $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

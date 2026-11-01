<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ProjectAccess
{
    public const ROLES = [
        'lead' => 'Administrador do projeto', 'developer' => 'Programador',
        'editor' => 'Editor', 'developer_editor' => 'Programador e editor', 'viewer' => 'Leitor',
        'owner' => 'Proprietário (legado)', 'tech_lead' => 'Líder técnico (legado)',
        'contributor' => 'Colaborador (legado)', 'reviewer' => 'Revisor (consulta; sem aprovação automática)',
    ];

    public function platform(?User $user): bool
    {
        return $user?->status === 'active' && $user->role()->whereIn('slug', ['admin', 'super-admin'])->exists();
    }

    public function organizationMember(User $user, int $organizationId): bool
    {
        return $user->status === 'active' && Organization::query()->whereKey($organizationId)->whereNull('archived_at')->exists()
            && $user->organizationMemberships()->where('organization_id', $organizationId)->where('status', 'active')->exists();
    }

    public function organizationAdmin(User $user, int $organizationId): bool
    {
        return $user->role?->slug !== 'viewer' && $this->organizationMember($user, $organizationId)
            && $user->organizationMemberships()->where('organization_id', $organizationId)->where('status', 'active')->where('role', 'admin')->exists();
    }

    public function view(User $user, Project $project): bool
    {
        if ($this->platform($user)) {
            return true;
        }
        if ($user->status !== 'active' || ($project->organization_id && ! $this->organizationMember($user, $project->organization_id))) {
            return false;
        }

        return ($project->organization_id && $this->organizationAdmin($user, $project->organization_id))
            || (Schema::hasTable('project_memberships') && $project->memberships()->where('user_id', $user->id)->where('status', 'active')->exists());
    }

    public function manage(User $user, Project $project): bool
    {
        if ($this->platform($user)) {
            return true;
        }
        if (! $this->view($user, $project) || $user->role?->slug === 'viewer') {
            return false;
        }

        return ($project->organization_id && $this->organizationAdmin($user, $project->organization_id))
            || $project->memberships()->where('user_id', $user->id)->where('status', 'active')->whereIn('role_in_project', ['owner', 'lead', 'tech_lead'])->exists();
    }

    public function contribute(User $user, Project $project, string $kind = 'tasks'): bool
    {
        if (! $this->view($user, $project) || $project->isArchived() || ($project->organization_id && ! $project->organization()->whereNull('archived_at')->exists())) {
            return false;
        }
        if ($this->manage($user, $project)) {
            return true;
        }
        if ($user->role?->slug === 'viewer') {
            return false;
        }
        $roles = $kind === 'content' ? ['editor', 'developer_editor', 'developer', 'contributor'] : ['developer', 'developer_editor', 'contributor'];

        return $project->memberships()->where('user_id', $user->id)->where('status', 'active')->whereIn('role_in_project', $roles)->exists();
    }

    public function scope(Builder $query, ?User $user): Builder
    {
        if (! $user || $user->status !== 'active') {
            return $query->whereRaw('1 = 0');
        }
        if ($this->platform($user)) {
            return $query;
        }
        if (! Schema::hasTable('project_memberships')) {
            return $query->whereRaw('1 = 0');
        }
        $query->where(function (Builder $query) use ($user): void {
            $query->whereHas('memberships', fn (Builder $q) => $q->where('user_id', $user->id)->where('status', 'active'));
            if ($user->role?->slug !== 'viewer' && Schema::hasColumn('projects', 'organization_id')) {
                $query->orWhereHas('organization.memberships', fn (Builder $q) => $q->where('user_id', $user->id)->where('status', 'active')->where('role', 'admin'));
            }
        });
        if (Schema::hasColumn('projects', 'organization_id')) {
            $query->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhereHas('organization', fn (Builder $org) => $org->whereNull('archived_at')->whereHas('memberships', fn (Builder $m) => $m->where('user_id', $user->id)->where('status', 'active'))));
        }

        return $query;
    }

    public function panel(User $user): bool
    {
        return $this->platform($user) || ($user->status === 'active' && Schema::hasTable('project_memberships') && $this->scope(Project::query(), $user)->exists())
            || ($user->status === 'active' && Schema::hasTable('organization_memberships') && $user->organizationMemberships()->where('status', 'active')->where('role', 'admin')->whereHas('organization', fn ($q) => $q->whereNull('archived_at'))->exists());
    }
}

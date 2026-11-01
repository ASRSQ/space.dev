<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationAdministrationService
{
    public function save(array $input, ?Organization $record = null): Organization
    {
        return DB::transaction(function () use ($input, $record) {
            $record = $record ? Organization::query()->lockForUpdate()->findOrFail($record->id) : null;
            Gate::authorize($record ? 'update' : 'create', $record ?? Organization::class);
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:150'], 'slug' => ['required', 'alpha_dash', 'max:150', Rule::unique('organizations', 'slug')->ignore($record?->id)], 'description' => ['nullable', 'string']])->validate();
            $record ??= new Organization;
            $record->fill($data)->save();

            return $record;
        });
    }

    public function archive(Organization $record, bool $archived): void
    {
        DB::transaction(function () use ($record, $archived) {
            $record = Organization::query()->lockForUpdate()->findOrFail($record->id);
            Gate::authorize('update', $record);
            $record->update(['archived_at' => $archived ? now() : null]);
        });
    }

    public function setMember(int $organizationId, int $userId, string $role, string $status): OrganizationMembership
    {
        return DB::transaction(function () use ($organizationId, $userId, $role, $status) {
            $org = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            Gate::authorize('update', $org);
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($org->archived_at || ! in_array($role, ['admin', 'member'], true) || ! in_array($status, ['active', 'revoked'], true) || ($status === 'active' && $user->status !== 'active')) {
                throw ValidationException::withMessages(['user_id' => 'Confira o estado da organização, do usuário e do vínculo.']);
            }
            if ($role === 'admin' && $user->role?->slug === 'viewer') {
                throw ValidationException::withMessages(['role' => 'Uma conta com papel global de leitor não pode administrar a organização.']);
            }
            $member = $org->memberships()->where('user_id', $userId)->first();
            // An organization admin may manage known members, not browse/link arbitrary accounts.
            abort_unless($member || app(ProjectAccess::class)->platform(auth()->user()), 403);
            if ($member?->role === 'admin' && $member->status === 'active' && ($role !== 'admin' || $status !== 'active')
                && ! $org->memberships()->where('user_id', '!=', $userId)->where('role', 'admin')->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->exists()) {
                throw ValidationException::withMessages(['role' => 'Mantenha um administrador ativo na organização.']);
            }

            return OrganizationMembership::query()->updateOrCreate(['organization_id' => $organizationId, 'user_id' => $userId], ['role' => $role, 'status' => $status]);
        });
    }

    public function linkProject(Organization $org, int $projectId): void
    {
        DB::transaction(function () use ($org, $projectId) {
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            $org = Organization::query()->lockForUpdate()->findOrFail($org->id);
            Gate::authorize('update', $org);
            Gate::authorize('manageMembers', $project);
            if ($org->archived_at || ($project->organization_id && $project->organization_id !== $org->id)) {
                throw ValidationException::withMessages(['project_id' => 'Transferências entre organizações exigem revisão separada.']);
            }
            $ids = $project->memberships()->where('status', 'active')->pluck('user_id')->push($project->owner_user_id)->unique();
            foreach ($ids as $id) {
                if (! app(ProjectAccess::class)->organizationMember(User::findOrFail($id), $org->id)) {
                    throw ValidationException::withMessages(['project_id' => 'Inclua primeiro o proprietário e os participantes ativos na organização.']);
                }
            }
            $project->update(['organization_id' => $org->id]);
        });
    }

    public function saveTeam(array $input, ?Team $team = null): Team
    {
        return DB::transaction(function () use ($input, $team) {
            $team = $team ? Team::query()->lockForUpdate()->findOrFail($team->id) : null;
            Gate::authorize($team ? 'update' : 'create', $team ?? Team::class);
            $data = Validator::make($input, ['organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')], 'name' => ['required', 'string', 'max:150'], 'lead_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')]])->validate();
            $org = Organization::query()->lockForUpdate()->findOrFail($data['organization_id']);
            Gate::authorize('update', $org);
            if ($org->archived_at || ($team && $team->organization_id !== $org->id)) {
                abort(403);
            }
            if (filled($data['lead_user_id'] ?? null) && ! app(ProjectAccess::class)->organizationMember(User::findOrFail($data['lead_user_id']), $org->id)) {
                throw ValidationException::withMessages(['lead_user_id' => 'O responsável deve ser membro ativo da organização.']);
            }
            $team ??= new Team;
            $team->fill(Arr::only($data, ['organization_id', 'name', 'lead_user_id']))->save();
            if ($team->lead_user_id) {
                TeamMembership::query()->updateOrCreate(['team_id' => $team->id, 'user_id' => $team->lead_user_id], ['status' => 'active']);
            }

            return $team;
        });
    }

    public function setTeamMember(Team $team, int $userId, string $status): void
    {
        DB::transaction(function () use ($team, $userId, $status) {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            Gate::authorize('update', $team);
            if ($team->archived_at || ! in_array($status, ['active', 'revoked'], true) || ! app(ProjectAccess::class)->organizationMember(User::findOrFail($userId), $team->organization_id)) {
                abort(403);
            }
            if ($status === 'revoked' && $team->lead_user_id === $userId) {
                throw ValidationException::withMessages(['user_id' => 'Troque o responsável antes de removê-lo da equipe.']);
            }
            TeamMembership::query()->updateOrCreate(['team_id' => $team->id, 'user_id' => $userId], ['status' => $status]);
        });
    }

    public function linkTeamProject(Team $team, int $projectId, bool $attach): void
    {
        DB::transaction(function () use ($team, $projectId, $attach) {
            $project = Project::query()->lockForUpdate()->findOrFail($projectId);
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            Gate::authorize('update', $team);
            Gate::authorize('manageMembers', $project);
            abort_unless(! $team->archived_at && $project->organization_id === $team->organization_id, 403);
            if ($attach) {
                $team->projects()->syncWithoutDetaching([$projectId]);
            } else {
                $team->projects()->detach($projectId);
            }
        });
    }

    public function archiveTeam(Team $team, bool $archived): void
    {
        DB::transaction(function () use ($team, $archived) {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            Gate::authorize('update', $team);
            $team->update(['archived_at' => $archived ? now() : null]);
        });
    }
}

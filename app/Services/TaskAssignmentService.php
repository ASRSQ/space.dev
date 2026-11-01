<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Task;
use App\Models\User;

class TaskAssignmentService
{
    public const ASSIGNABLE_ROLES = ['owner', 'lead', 'tech_lead', 'contributor', 'developer', 'developer_editor'];

    public function eligibilityMessage(int $userId, int $projectId): string
    {
        $user = User::find($userId);
        $project = Project::find($projectId);
        if (! $user || ! $project) {
            return 'Usuário ou projeto não encontrado.';
        }
        if ($user->status !== 'active') {
            return 'A conta do usuário não está ativa.';
        }
        if ($user->role?->slug === 'viewer') {
            return 'O papel global de leitor não permite receber tarefas operacionais.';
        }
        if ($project->organization_id && ! app(ProjectAccess::class)->organizationMember($user, $project->organization_id)) {
            return 'O usuário precisa ser membro ativo da organização do projeto.';
        }
        $membership = $project->memberships()->where('user_id', $userId)->where('status', 'active')->first();
        if (! $membership) {
            return 'Falta um vínculo ativo com o projeto. Adicione o usuário em Membros dos projetos.';
        }
        if (! in_array($membership->role_in_project, self::ASSIGNABLE_ROLES, true)) {
            return 'O papel no projeto não permite receber tarefas. Se necessário, use Programador ou Programador e editor.';
        }

        return 'Usuário elegível para receber tarefas neste projeto.';
    }

    public function optionsForEditing(?int $projectId, ?Task $task): array
    {
        if ($task && auth()->user() && ! app(ProjectAccess::class)->view(auth()->user(), $task->project)) {
            return [];
        }
        $options = $this->optionsForProject($projectId);
        if ($task?->assigned_user_id && ! array_key_exists($task->assigned_user_id, $options)) {
            $user = $task->assignedUser;
            if ($user) {
                $reason = auth()->user() && ! app(ProjectAccess::class)->manage(auth()->user(), $task->project)
                    ? 'atribuição atual (somente leitura)' : 'não elegível; atribuição mantida';
                $options[$user->id] = "{$user->name} (#{$user->id}) — {$reason}";
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
            ->whereHas('role', fn ($q) => $q->where('slug', '!=', 'viewer'))
            ->lockForUpdate()
            ->first(['id']) !== null;

        if (! $userIsActive) {
            return false;
        }

        $project = Project::query()->find($projectId);
        if ($project?->organization_id && ! app(ProjectAccess::class)->organizationMember(User::findOrFail($userId), $project->organization_id)) {
            return false;
        }

        return ProjectMembership::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->whereIn('role_in_project', self::ASSIGNABLE_ROLES)
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /** @return array<int, string> */
    public function optionsForProject(?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        $project = Project::query()->find($projectId);
        if (! $project || (auth()->user() && ! app(ProjectAccess::class)->view(auth()->user(), $project))) {
            return [];
        }

        $options = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('slug', '!=', 'viewer'))
            ->when($project->organization_id, fn ($q) => $q->whereHas('organizationMemberships', fn ($m) => $m->where('organization_id', $project->organization_id)->where('status', 'active')->whereHas('organization', fn ($org) => $org->whereNull('archived_at'))))
            ->whereHas('projectMemberships', fn ($query) => $query
                ->where('project_id', $projectId)
                ->where('status', 'active')->whereIn('role_in_project', self::ASSIGNABLE_ROLES))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => "{$user->name} (#{$user->getKey()})",
            ])
            ->all();

        return auth()->user() && ! app(ProjectAccess::class)->manage(auth()->user(), $project)
            ? array_intersect_key($options, [auth()->id() => true]) : $options;
    }

    /** @return array<int, string> */
    public function projectOptionsForTaskCreation(): array
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->where('status', 'active')
            ->where('is_archived', false)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()->filter(fn (Project $project): bool => app(ProjectAccess::class)->contribute(auth()->user(), $project))->pluck('name', 'id')->all();
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

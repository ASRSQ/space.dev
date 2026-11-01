<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\Problem;
use App\Models\Project;
use App\Models\PromptTemplate;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccess;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PermissionMatrix extends Page
{
    protected string $view = 'filament.pages.permission-matrix';

    protected static ?string $title = 'Papéis e permissões';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public ?string $userId = null;

    public ?string $projectId = null;

    public static function canAccess(): bool
    {
        return app(ProjectAccess::class)->platform(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->userId = (string) auth()->id();
    }

    public function users(): array
    {
        abort_unless(static::canAccess(), 403);

        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function projects(): array
    {
        abort_unless(static::canAccess(), 403);

        return Project::query()->visibleTo(auth()->user())->pluck('name', 'id')->all();
    }

    public function roles(): Collection
    {
        abort_unless(static::canAccess(), 403);

        return Role::query()->get(['name', 'slug']);
    }

    public function matrix(): array
    {
        abort_unless(static::canAccess(), 403);
        $user = User::find($this->userId);
        if (! $user) {
            return [];
        }
        $gate = Gate::forUser($user);
        $project = Project::find($this->projectId);
        $organization = $project?->organization;
        $matrix = [
            'Usuários' => [$gate->allows('viewAny', User::class), $gate->allows('create', User::class), $gate->allows('update', $user), $gate->allows('update', $user) ? 'Desativação protegida' : false, '—'],
            'Organizações' => [$gate->allows('viewAny', Organization::class), $gate->allows('create', Organization::class), $organization ? $gate->allows('update', $organization) : 'Selecione projeto com organização', $organization ? $gate->allows('update', $organization) : 'Selecione projeto com organização', '—'],
        ];
        if (! $project) {
            return $matrix;
        }
        $task = new Task(['project_id' => $project->id]);
        $task->assigned_user_id = $user->id;
        $task->setRelation('project', $project);
        $problem = new Problem(['project_id' => $project->id]);
        $problem->setRelation('project', $project);
        $matrix['Projetos'] = [$gate->allows('view', $project), $gate->allows('create', Project::class), $gate->allows('update', $project), $gate->allows('archive', $project), '—'];
        $matrix['Tarefas (próprias)'] = [$gate->allows('view', $task), $gate->allows('create', Task::class) && app(ProjectAccess::class)->contribute($user, $project), $gate->allows('update', $task), $gate->allows('assign', $task), '—'];
        $matrix['Problemas'] = [$gate->allows('view', $problem), $gate->allows('create', Problem::class) && app(ProjectAccess::class)->contribute($user, $project), $gate->allows('update', $problem), false, '—'];
        $content = [$gate->allows('view-project-documentation', $project), $gate->allows('edit-project-documentation', $project), $gate->allows('edit-project-documentation', $project), false, $gate->allows('review-project-documentation', $project)];
        $matrix['Documentos'] = $content;
        $matrix['Contextos técnicos'] = $content;
        $prompt = new PromptTemplate(['project_id' => $project->id]);
        $prompt->setRelation('project', $project);
        $matrix['Prompts'] = [$gate->allows('view', $prompt), $gate->allows('create', [PromptTemplate::class, $project]), $gate->allows('update', $prompt), $gate->allows('archive', $prompt), $gate->allows('review', $prompt)];

        return $matrix;
    }
}

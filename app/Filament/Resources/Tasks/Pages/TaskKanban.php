<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAssignmentService;
use App\Services\TaskCreationService;
use App\Services\TaskLifecycleService;
use Asignua\FilamentKanban\Events\KanbanRecordMoved;
use Asignua\FilamentKanban\Pages\KanbanResourcePage;
use Asignua\FilamentKanban\Support\KanbanColumn;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskKanban extends KanbanResourcePage
{
    protected static string $resource = TaskResource::class;

    protected static string $recordTitleAttribute = 'title';

    protected static ?string $recordSortAttribute = null;

    protected static string $toolbarView = 'filament.tasks.kanban-toolbar';

    protected static string $headerView = 'filament.tasks.kanban-header';

    protected static ?string $title = 'Quadro de tarefas';

    public ?string $deadlineFilter = '';

    public ?string $deadlineSort = '';

    public ?string $projectFilter = '';

    public ?string $priorityFilter = '';

    public ?string $assigneeFilter = '';

    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', Task::class);
    }

    protected function statuses(): Collection
    {
        return collect([
            ['id' => 'todo', 'title' => 'A fazer', 'value' => 'todo', 'color' => 'gray'],
            ['id' => 'in_progress', 'title' => 'Em andamento', 'value' => 'in_progress', 'color' => 'primary'],
            ['id' => 'review', 'title' => 'Em revisão', 'value' => 'review', 'color' => 'warning'],
            ['id' => 'done', 'title' => 'Concluído', 'value' => 'done', 'color' => 'success'],
        ]);
    }

    protected function columnHeaderExtras(KanbanColumn $column): array
    {
        $actionName = match ($column->id) {
            'todo' => 'createTodo',
            'in_progress' => 'createInProgress',
            'review' => 'createReview',
            'done' => 'createDone',
            default => null,
        };

        if ($actionName === null) {
            return [];
        }

        return [new HtmlString(sprintf(
            '<button type="button" class="fi-kanban-create rounded-md p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-950 dark:hover:bg-white/10 dark:hover:text-white" wire:click="mountAction(\'%s\')" aria-label="Criar tarefa em %s" title="Criar tarefa">+</button>',
            $actionName,
            e($column->title),
        ))];
    }

    public function createTodoAction(): Action
    {
        return $this->quickCreateAction('createTodo', 'todo', 'A fazer');
    }

    public function createInProgressAction(): Action
    {
        return $this->quickCreateAction('createInProgress', 'in_progress', 'Em andamento');
    }

    public function createReviewAction(): Action
    {
        return $this->quickCreateAction('createReview', 'review', 'Em revisão');
    }

    public function createDoneAction(): Action
    {
        return $this->quickCreateAction('createDone', 'done', 'Concluído');
    }

    private function quickCreateAction(string $name, string $status, string $label): Action
    {
        return Action::make($name)
            ->label('Criar tarefa')
            ->modalHeading('Nova tarefa · '.$label)
            ->modalSubmitActionLabel('Criar tarefa')
            ->authorize(fn (): bool => Gate::allows('create', Task::class))
            ->schema([
                ...TaskResource::deadlineFields(),
                TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(200),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3),
                Select::make('project_id')
                    ->label('Projeto')
                    ->options(fn (): array => app(TaskAssignmentService::class)->projectOptionsForTaskCreation())
                    ->searchable()
                    ->required()
                    ->live()
                    ->rules(['required', 'integer', Rule::exists('projects', 'id')]),
                Select::make('task_type')
                    ->label('Tipo')
                    ->options(TaskResource::typeOptions())
                    ->default('feature')
                    ->required()
                    ->rules(['required', Rule::in(Task::TYPES)]),
                Select::make('priority')
                    ->label('Prioridade')
                    ->options(TaskResource::priorityOptions())
                    ->default('medium')
                    ->required()
                    ->rules(['required', Rule::in(Task::PRIORITIES)]),
                Select::make('assigned_user_id')
                    ->label('Responsável')
                    ->options(fn (Get $get): array => app(TaskAssignmentService::class)
                        ->optionsForProject(filled($get('project_id')) ? (int) $get('project_id') : null))
                    ->searchable()
                    ->placeholder('Sem responsável')
                    ->rules(['nullable', 'integer']),
            ])
            ->action(function (array $data) use ($status): void {
                try {
                    app(TaskCreationService::class)->create($data, $status);
                } catch (ValidationException|DomainException) {
                    Notification::make()
                        ->title('Tarefa não criada')
                        ->body('Confira se o projeto está ativo e se o responsável está associado a ele.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Tarefa criada')
                    ->success()
                    ->send();
            });
    }

    protected function modifyRecordsQuery(Builder $query): Builder
    {
        return $query
            ->deadline($this->deadlineFilter)
            ->when(in_array($this->deadlineSort, ['asc', 'desc'], true), fn (Builder $query): Builder => $query->orderBy('due_date', $this->deadlineSort)->orderBy('id'))
            ->whereIn('status', TaskLifecycleService::OPERATIONAL_STATUSES)
            ->where('is_archived', false)
            ->whereNull('archived_at')
            ->when(filled($this->projectFilter), fn (Builder $query): Builder => $query->where('project_id', (int) $this->projectFilter))
            ->when(filled($this->priorityFilter) && in_array($this->priorityFilter, Task::PRIORITIES, true), fn (Builder $query): Builder => $query->where('priority', $this->priorityFilter))
            ->when(filled($this->assigneeFilter), fn (Builder $query): Builder => $query->where('assigned_user_id', (int) $this->assigneeFilter));
    }

    protected function recordRelations(): array
    {
        return ['project', 'assignedUser'];
    }

    protected function recordBadges(Model $record, KanbanColumn $column): array
    {
        if (! $record instanceof Task) {
            return [];
        }

        return [
            ['label' => $record->priority, 'color' => match ($record->priority) {
                'urgent' => 'danger',
                'high' => 'warning',
                'low' => 'gray',
                default => 'primary',
            }],
            ['label' => $record->task_type, 'color' => 'gray'],
            ['label' => $record->deadline_status, 'color' => $record->deadline_status === 'Atrasada' ? 'danger' : 'gray'],
        ];
    }

    protected function recordDescription(Model $record): ?string
    {
        if (! $record instanceof Task) {
            return null;
        }

        return trim(implode(' · ', array_filter([
            $record->project?->name,
            $record->due_date ? 'Entrega: '.$record->due_date->format('d/m/Y') : null,
            $record->assignedUser?->name ? 'Responsável: '.$record->assignedUser->name : null,
        ])));
    }

    protected function recordUrl(Model $record): ?string
    {
        return $record instanceof Task
            ? TaskResource::getUrl('view', ['record' => $record])
            : null;
    }

    protected function canMove(Model $record, string $from, string $to): bool
    {
        return $record instanceof Task
            && in_array($to, TaskLifecycleService::OPERATIONAL_STATUSES, true)
            && ! $record->isArchived()
            && ! $record->project()->first()?->isArchived()
            && Gate::allows('update', $record);
    }

    protected function isRecordDraggable(Model $record): bool
    {
        return $record instanceof Task
            && ! $record->isArchived()
            && ! $record->project()->first()?->isArchived();
    }

    /** @param array<int, mixed> $fromOrderedIds @param array<int, mixed> $toOrderedIds @param array<string, mixed> $data */
    protected function onRecordTransitioned(int|string $recordId, string $from, string $to, array $fromOrderedIds, array $toOrderedIds, array $data): void
    {
        $task = Task::query()->with('project')->findOrFail($recordId);

        Gate::authorize('update', $task);

        if ($task->isArchived() || $task->project->isArchived()
            || ! in_array($to, TaskLifecycleService::OPERATIONAL_STATUSES, true)) {
            throw new DomainException('A tarefa não pode ser movimentada neste estado.');
        }

        app(TaskLifecycleService::class)->transitionTo($task, $to);
    }

    /** @param array<int, mixed> $fromOrderedIds @param array<int, mixed> $toOrderedIds @param array<string, mixed> $data */
    protected function moveRecord(Model $record, string $from, string $to, array $fromOrderedIds, array $toOrderedIds, array $data = []): void
    {
        $key = $record->getKey();
        $previous = $this->kanbanTransitionData;
        $this->kanbanTransitionData = $data;

        try {
            DB::transaction(function () use ($key, $from, $to, $fromOrderedIds, $toOrderedIds, $data): void {
                $this->onRecordTransitioned($key, $from, $to, $fromOrderedIds, $toOrderedIds, $data);
            });
        } catch (DomainException) {
            Notification::make()
                ->title('Movimentação não realizada')
                ->body('O estado atual da tarefa ou do projeto não permite essa operação.')
                ->warning()
                ->send();

            return;
        } finally {
            $this->kanbanTransitionData = $previous;
        }

        $moved = $this->findRecord($key) ?? $record;

        event(new KanbanRecordMoved($moved, $from, $to, static::class, $data));
        $this->onRecordMoved($moved, $from, $to, $data);
    }

    protected function onRecordMoved(Model $record, string $from, string $to, array $data): void
    {
        Notification::make()
            ->title('Tarefa movimentada')
            ->success()
            ->send();
    }

    /** @return array<int, array{string, string}> */
    public function projectFilterOptions(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->map(fn ($name, $id): array => [(string) $id, (string) $name])->values()->all();
    }

    /** @return array<int, array{string, string}> */
    public function assigneeFilterOptions(): array
    {
        return User::query()
            ->whereHas('assignedTasks')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [(string) $user->getKey(), $user->name])
            ->all();
    }

    /** @return array<string, string> */
    public function priorityFilterOptions(): array
    {
        return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'urgent' => 'Urgente'];
    }
}

<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Concerns\ProtectsEditorFromStaleUpdates;
use App\Filament\Resources\ProjectMemberships\ProjectMembershipResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccess;
use App\Services\TaskAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    use ProtectsEditorFromStaleUpdates;

    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('members')->label('Membros do projeto')
                ->visible(fn () => Gate::allows('manageMembers', $this->getRecord()->project))
                ->url(fn () => ProjectMembershipResource::getUrl('index', ['tableFilters' => ['project_id' => ['value' => $this->getRecord()->project_id]]])),
            Action::make('checkAssignee')->label('Verificar responsável')
                ->visible(fn () => Gate::allows('assign', $this->getRecord()))
                ->schema([Select::make('user_id')->label('Usuário')->searchable()->required()->options(function () {
                    $project = $this->getRecord()->project;

                    return User::query()->when(! app(ProjectAccess::class)->platform(auth()->user()), fn ($q) => $q->whereHas('projectMemberships', fn ($m) => $m->where('project_id', $project->id)))->orderBy('name')->pluck('name', 'id');
                })])
                ->action(function (array $data) {
                    Gate::authorize('assign', $this->getRecord()->fresh());
                    Notification::make()->title('Elegibilidade do responsável')->body(app(TaskAssignmentService::class)->eligibilityMessage((int) $data['user_id'], $this->getRecord()->project_id))->send();
                }),
        ];
    }

    protected function editableRecordFields(): array
    {
        return ['title', 'description', 'task_type', 'priority', 'assigned_user_id', 'start_date', 'due_date'];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = Arr::only($data, [
            'title',
            'description',
            'task_type',
            'priority',
            'assigned_user_id',
            'start_date',
            'due_date',
        ]);

        return DB::transaction(function () use ($record, $data): Model {
            $task = Task::query()->lockForUpdate()->findOrFail($record->getKey());

            $project = Project::query()->lockForUpdate()->findOrFail($task->project_id);
            Gate::authorize('update', $task);

            if ($project->isArchived()) {
                throw ValidationException::withMessages([
                    'data.title' => 'Tarefas de projetos arquivados são somente para consulta.',
                ]);
            }

            $this->assertEditorRevision($task);

            $assignedUserId = array_key_exists('assigned_user_id', $data) ? (filled($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null) : $task->assigned_user_id;

            if ($assignedUserId !== $task->assigned_user_id) {
                Gate::authorize('assign', $task);
            }

            /* Preserve omission of the field in scoped forms. */
            $assignedUserId = filled($assignedUserId)
                ? (int) $assignedUserId
                : null;

            if ($assignedUserId !== null
                && $assignedUserId !== $task->assigned_user_id
                && ! app(TaskAssignmentService::class)->isEligible($assignedUserId, (int) $task->project_id)) {
                throw ValidationException::withMessages([
                    'data.assigned_user_id' => 'O responsável precisa ser um usuário ativo associado ao projeto.',
                ]);
            }

            $data['assigned_user_id'] = $assignedUserId;
            $task->update($data);
            $this->captureEditorRevision($task->refresh());

            return $task;
        });
    }
}

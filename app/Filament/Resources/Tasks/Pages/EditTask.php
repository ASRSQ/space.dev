<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskAssignmentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

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
            Gate::authorize('update', $task);

            $project = Project::query()->lockForUpdate()->findOrFail($task->project_id);

            if ($project->isArchived()) {
                throw ValidationException::withMessages([
                    'data.title' => 'Tarefas de projetos arquivados são somente para consulta.',
                ]);
            }

            $assignedUserId = filled($data['assigned_user_id'] ?? null)
                ? (int) $data['assigned_user_id']
                : null;

            if ($assignedUserId !== null
                && ! app(TaskAssignmentService::class)->isEligible($assignedUserId, (int) $task->project_id)) {
                throw ValidationException::withMessages([
                    'data.assigned_user_id' => 'O responsável precisa ser um usuário ativo associado ao projeto.',
                ]);
            }

            $data['assigned_user_id'] = $assignedUserId;
            $task->update($data);

            return $task;
        });
    }
}

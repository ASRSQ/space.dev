<?php

namespace App\Services;

use App\Models\Task;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskCreationService
{
    /** @param array<string, mixed> $input */
    public function create(array $input, string $initialStatus = 'todo'): Task
    {
        if (! in_array($initialStatus, TaskLifecycleService::OPERATIONAL_STATUSES, true)) {
            throw new DomainException('O status inicial solicitado não é operacional.');
        }

        $data = Arr::only($input, [
            'project_id', 'title', 'description', 'task_type', 'priority', 'assigned_user_id', 'start_date', 'due_date',
        ]);

        return DB::transaction(function () use ($data, $initialStatus): Task {
            Gate::authorize('create', Task::class);

            $data = Validator::make($data, [
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:200'],
                'description' => ['nullable', 'string'],
                'task_type' => ['required', Rule::in(Task::TYPES)],
                'priority' => ['required', Rule::in(Task::PRIORITIES)],
                'assigned_user_id' => ['nullable', 'integer'],
            ] + Task::deadlineRules())->validate();

            $assignment = app(TaskAssignmentService::class);
            $projectId = (int) $data['project_id'];

            if (! $assignment->canCreateInProject($projectId)) {
                throw ValidationException::withMessages([
                    'data.project_id' => 'Novas tarefas só podem ser criadas em projetos ativos.',
                ]);
            }

            $assigneeId = filled($data['assigned_user_id'] ?? null)
                ? (int) $data['assigned_user_id']
                : null;

            if ($assigneeId !== null && ! $assignment->isEligible($assigneeId, $projectId)) {
                throw ValidationException::withMessages([
                    'data.assigned_user_id' => 'O responsável precisa ser um usuário ativo associado ao projeto.',
                ]);
            }

            $data['project_id'] = $projectId;
            $data['assigned_user_id'] = $assigneeId;

            $task = new Task($data);
            $task->status = $initialStatus;
            $task->completed_at = $initialStatus === 'done' ? now() : null;
            $task->reporter_user_id = Auth::guard('web')->id();
            $task->saveOrFail();

            return $task;
        });
    }
}

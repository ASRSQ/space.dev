<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskLifecycleService
{
    public const OPERATIONAL_STATUSES = ['todo', 'in_progress', 'review', 'done'];

    public function transitionTo(Task $task, string $newStatus, ?string $reason = null): void
    {
        if (! in_array($newStatus, self::OPERATIONAL_STATUSES, true)) {
            throw new DomainException('O status solicitado não é operacional.');
        }

        $this->transition($task, $newStatus, false, false, $reason);
    }

    public function archive(Task $task): void
    {
        $this->transition($task, 'archived', true, false, null);
    }

    public function restore(Task $task): void
    {
        $this->transition($task, 'todo', false, true, null);
    }

    private function transition(Task $task, string $newStatus, bool $archiving, bool $restoring, ?string $reason): void
    {
        if ($reason !== null && mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => 'O motivo não pode exceder 255 caracteres.',
            ]);
        }

        DB::transaction(function () use ($task, $newStatus, $archiving, $restoring, $reason): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->getKey());

            Gate::authorize('update', $lockedTask);

            $project = Project::query()
                ->lockForUpdate()
                ->findOrFail($lockedTask->project_id);

            if ($project->isArchived()) {
                throw new DomainException('Tarefas de projetos arquivados são somente para consulta.');
            }

            if ($archiving) {
                if (! in_array($lockedTask->status, self::OPERATIONAL_STATUSES, true)
                    && $lockedTask->status !== 'archived') {
                    throw new DomainException('O estado atual da tarefa não pode ser arquivado.');
                }

                $lockedTask->status = 'archived';
                $lockedTask->is_archived = true;
                $lockedTask->archived_at ??= now();
            } elseif ($restoring) {
                if (! $lockedTask->isArchived()) {
                    throw new DomainException('Somente tarefas arquivadas podem ser restauradas.');
                }

                $lockedTask->status = 'todo';
                $lockedTask->is_archived = false;
                $lockedTask->archived_at = null;
            } else {
                if ($lockedTask->isArchived()) {
                    throw new DomainException('Restaure a tarefa antes de alterar seu status operacional.');
                }

                if (! in_array($lockedTask->status, self::OPERATIONAL_STATUSES, true)) {
                    throw new DomainException('O estado atual da tarefa não pode ser alterado.');
                }

                $lockedTask->status = $newStatus;
                $lockedTask->is_archived = false;
                $lockedTask->archived_at = null;
            }

            $oldStatus = $lockedTask->getOriginal('status');
            if ($oldStatus !== $newStatus) {
                if ($newStatus === 'done') {
                    $lockedTask->completed_at ??= now();
                } elseif (! $archiving) {
                    $lockedTask->completed_at = null;
                }
            }
            $lockedTask->saveOrFail();

            if ($oldStatus === $newStatus) {
                return;
            }

            $actorId = Auth::guard('web')->id();

            if ($actorId === null) {
                throw new DomainException('Não foi possível identificar o usuário que alterou a tarefa.');
            }

            TaskStatusHistory::query()->create([
                'task_id' => $lockedTask->getKey(),
                'changed_by_user_id' => $actorId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
            ]);
        });

        $task->refresh();
    }
}

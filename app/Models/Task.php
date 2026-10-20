<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Task extends Model
{
    public const TYPES = ['feature', 'bug', 'refactor', 'documentation', 'chore'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const STATUSES = ['todo', 'in_progress', 'review', 'done', 'archived'];

    protected $table = 'tasks';

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'task_type',
        'priority',
        'assigned_user_id',
        'start_date',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            Validator::make([
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
            ], self::deadlineRules())->validate();
        });
    }

    public static function deadlineRules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', Rule::when(fn ($input) => filled($input->start_date), ['after_or_equal:start_date'])],
        ];
    }

    public function scopeDeadline(Builder $query, ?string $filter): void
    {
        if ($filter === 'none') {
            $query->whereNull('due_date');
        } elseif (in_array($filter, ['overdue', 'soon'], true)) {
            $query->whereIn('status', ['todo', 'in_progress', 'review'])
                ->where('is_archived', false)->whereNull('archived_at');
            if ($filter === 'overdue') {
                $query->where('due_date', '<', today(config('app.timezone'))->toDateString());
            } else {
                $query->whereDate('due_date', '>=', today(config('app.timezone'))->toDateString())
                    ->whereDate('due_date', '<=', today(config('app.timezone'))->addDays(7)->toDateString());
            }
        }
    }

    public function getDaysRemainingAttribute(): ?int
    {
        return $this->due_date && $this->status !== 'done' && ! $this->isArchived()
            ? (int) today(config('app.timezone'))->diffInDays(Carbon::parse($this->due_date->toDateString(), config('app.timezone')), false)
            : null;
    }

    public function getDeadlineStatusAttribute(): string
    {
        if (! $this->due_date) {
            return 'Sem prazo';
        }
        if ($this->status === 'done' || ($this->isArchived() && $this->completed_at)) {
            if (! $this->completed_at) {
                return 'Conclusão sem data conhecida';
            }

            return $this->completed_at->copy()->timezone(config('app.timezone'))->toDateString() <= $this->due_date->toDateString()
                ? 'Concluída no prazo' : 'Concluída com atraso';
        }
        if ($this->isArchived()) {
            return 'Arquivada';
        }

        return match (true) {
            $this->days_remaining < 0 => 'Atrasada',
            $this->days_remaining === 0 => 'Vence hoje',
            default => 'No prazo',
        };
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived'
            || $this->is_archived
            || $this->archived_at !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(TaskRelation::class, 'source_task_id');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(TaskRelation::class, 'target_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class, 'task_id');
    }
}

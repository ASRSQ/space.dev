<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class TaskRelation extends Model
{
    protected $table = 'task_relations';

    protected $fillable = [
        'source_task_id',
        'target_task_id',
        'relation_type',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $relation): void {
            if ($relation->source_task_id !== null
                && $relation->target_task_id !== null
                && (string) $relation->source_task_id === (string) $relation->target_task_id) {
                throw new InvalidArgumentException(
                    'A task cannot be related to itself.'
                );
            }
        });
    }

    public function sourceTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'source_task_id');
    }

    public function targetTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'target_task_id');
    }

}

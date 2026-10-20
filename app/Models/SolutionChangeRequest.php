<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolutionChangeRequest extends Model
{
    protected $table = 'solution_change_requests';

    protected $fillable = [
        'solution_id',
        'proposed_version_number',
        'proposed_steps_markdown',
        'proposed_code_snippet',
        'proposed_rollback_plan',
        'reason',
        'status',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'proposed_version_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'solution_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SolutionChangeRequestDecision::class, 'change_request_id');
    }

}

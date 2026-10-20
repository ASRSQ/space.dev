<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Problem extends Model
{
    protected $table = 'problems';

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'severity',
        'status',
        'reporter_user_id',
        'is_archived',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function solutions(): HasMany
    {
        return $this->hasMany(Solution::class, 'problem_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ProblemDiagnosis::class, 'problem_id');
    }

    public function eventHistories(): HasMany
    {
        return $this->hasMany(ProblemEventHistory::class, 'problem_id');
    }

}

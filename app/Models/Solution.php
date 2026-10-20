<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solution extends Model
{
    protected $table = 'solutions';

    protected $fillable = [
        'problem_id',
        'title',
        'current_version_id',
        'status',
        'author_user_id',
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

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class, 'problem_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SolutionVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SolutionVersion::class, 'solution_id');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(SolutionValidation::class, 'solution_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(SolutionChangeRequest::class, 'solution_id');
    }

}

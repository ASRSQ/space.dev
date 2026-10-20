<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolutionVersion extends Model
{
    protected $table = 'solution_versions';

    protected $fillable = [
        'solution_id',
        'version_number',
        'solution_steps_markdown',
        'code_snippet',
        'rollback_plan',
        'changelog',
        'created_by_user_id',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'solution_id');
    }

    public function currentForSolutions(): HasMany
    {
        return $this->hasMany(Solution::class, 'current_version_id');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(SolutionValidation::class, 'solution_version_id');
    }

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolutionValidation extends Model
{
    protected $table = 'solution_validations';

    protected $fillable = [
        'solution_id',
        'solution_version_id',
        'validated_by_user_id',
        'outcome',
        'test_log',
        'validated_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'solution_id');
    }

    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function solutionVersion(): BelongsTo
    {
        return $this->belongsTo(SolutionVersion::class, 'solution_version_id');
    }

}

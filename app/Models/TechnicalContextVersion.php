<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalContextVersion extends Model
{
    protected $table = 'technical_context_versions';

    protected $fillable = [
        'technical_context_id',
        'version_number',
        'architecture_summary',
        'tech_stack_json',
        'conventions_markdown',
        'changelog',
        'created_by_user_id',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'tech_stack_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function technicalContext(): BelongsTo
    {
        return $this->belongsTo(TechnicalContext::class, 'technical_context_id');
    }

    public function currentForTechnicalContexts(): HasMany
    {
        return $this->hasMany(TechnicalContext::class, 'current_version_id');
    }

}

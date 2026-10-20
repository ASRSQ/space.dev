<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalContext extends Model
{
    protected $table = 'technical_contexts';

    protected $fillable = [
        'project_id',
        'title',
        'slug',
        'section_key',
        'description',
        'current_version_id',
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

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TechnicalContextVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TechnicalContextVersion::class, 'technical_context_id');
    }

    public function draft(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TechnicalContextDraft::class, 'technical_context_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(TechnicalContextChangeRequest::class, 'technical_context_id');
    }

}

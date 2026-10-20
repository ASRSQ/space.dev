<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalContextDraft extends Model
{
    protected $table = 'technical_context_drafts';

    protected $fillable = [
        'technical_context_id',
        'architecture_summary',
        'tech_stack_json',
        'conventions_markdown',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tech_stack_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function technicalContext(): BelongsTo
    {
        return $this->belongsTo(TechnicalContext::class, 'technical_context_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}

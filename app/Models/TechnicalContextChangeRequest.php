<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalContextChangeRequest extends Model
{
    protected $table = 'technical_context_change_requests';

    protected $fillable = [
        'technical_context_id',
        'proposed_version_number',
        'proposed_architecture_summary',
        'proposed_tech_stack_json',
        'proposed_conventions_markdown',
        'reason',
        'status',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'proposed_version_number' => 'integer',
            'proposed_tech_stack_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function technicalContext(): BelongsTo
    {
        return $this->belongsTo(TechnicalContext::class, 'technical_context_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TechnicalContextChangeRequestDecision::class, 'change_request_id');
    }

}

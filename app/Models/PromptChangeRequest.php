<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptChangeRequest extends Model
{
    protected $table = 'prompt_change_requests';

    protected $fillable = [
        'prompt_template_id',
        'proposed_version_number',
        'proposed_system_prompt',
        'proposed_user_prompt_template',
        'proposed_model_parameters_json',
        'reason',
        'status',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'proposed_version_number' => 'integer',
            'proposed_model_parameters_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PromptChangeRequestDecision::class, 'change_request_id');
    }

}

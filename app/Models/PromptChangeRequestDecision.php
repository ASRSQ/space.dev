<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptChangeRequestDecision extends Model
{
    protected $table = 'prompt_change_request_decisions';

    protected $fillable = [
        'change_request_id',
        'decided_by_user_id',
        'decision',
        'comments',
        'decided_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(PromptChangeRequest::class, 'change_request_id');
    }

}

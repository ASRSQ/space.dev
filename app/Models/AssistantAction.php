<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantAction extends Model
{
    protected $table = 'assistant_actions';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'action_type',
        'target_resource_type',
        'target_resource_id',
        'execution_status',
        'parameters_json',
        'result_json',
        'executed_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'parameters_json' => 'array',
            'result_json' => 'array',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'message_id');
    }

}

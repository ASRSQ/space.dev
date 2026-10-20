<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantMessage extends Model
{
    protected $table = 'assistant_messages';

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'message_content',
        'tokens_used',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tokens_used' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AssistantAction::class, 'message_id');
    }

}

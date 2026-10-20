<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentChangeRequest extends Model
{
    protected $table = 'document_change_requests';

    protected $fillable = [
        'document_id',
        'proposed_version_number',
        'proposed_title',
        'proposed_content',
        'reason',
        'status',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(DocumentChangeRequestDecision::class, 'change_request_id');
    }

}

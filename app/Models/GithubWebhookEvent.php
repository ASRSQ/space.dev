<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubWebhookEvent extends Model
{
    protected $table = 'github_webhook_events';

    protected $fillable = [
        'delivery_id',
        'event_type',
        'repository_full_name',
        'payload_hash',
        'action',
        'processed_status',
        'error_message',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

}

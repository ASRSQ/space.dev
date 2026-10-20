<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Builders\ImmutableAuditLogBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'project_id',
        'action',
        'resource_type',
        'resource_id',
        'old_values_json',
        'new_values_json',
        'ip_address',
        'user_agent',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_values_json' => 'array',
            'new_values_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function newEloquentBuilder($query): ImmutableAuditLogBuilder
    {
        return new ImmutableAuditLogBuilder($query);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}

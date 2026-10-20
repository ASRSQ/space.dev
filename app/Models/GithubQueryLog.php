<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubQueryLog extends Model
{
    protected $table = 'github_query_logs';

    protected $fillable = [
        'project_id',
        'endpoint',
        'http_method',
        'request_summary',
        'http_status',
        'response_time_ms',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

}

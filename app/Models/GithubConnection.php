<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubConnection extends Model
{
    protected $table = 'github_connections';

    protected $hidden = [
        'access_token_encrypted',
    ];

    protected $fillable = [
        'project_id',
        'repository_owner',
        'repository_name',
        'installation_id',
        'access_token_encrypted',
        'token_key_id',
        'token_expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    protected $table = 'ai_provider_configs';

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected $fillable = [
        'provider_name',
        'model_alias',
        'api_key_encrypted',
        'key_vault_reference',
        'is_active',
        'rate_limit_rpm',
    ];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'rate_limit_rpm' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

}

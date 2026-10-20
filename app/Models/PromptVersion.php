<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptVersion extends Model
{
    protected $table = 'prompt_versions';

    protected $fillable = [
        'prompt_template_id',
        'version_number',
        'system_prompt',
        'user_prompt_template',
        'model_parameters_json',
        'changelog',
        'created_by_user_id',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'model_parameters_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function currentForPromptTemplates(): HasMany
    {
        return $this->hasMany(PromptTemplate::class, 'current_version_id');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(PromptVariable::class, 'prompt_version_id');
    }

}

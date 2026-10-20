<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptTemplate extends Model
{
    protected $table = 'prompt_templates';

    protected $fillable = [
        'project_id',
        'category_id',
        'name',
        'slug',
        'description',
        'current_version_id',
        'is_archived',
        'archived_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class, 'prompt_template_id');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(PromptVariable::class, 'prompt_template_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(PromptFavorite::class, 'prompt_template_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(PromptChangeRequest::class, 'prompt_template_id');
    }

}

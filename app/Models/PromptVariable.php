<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptVariable extends Model
{
    protected $table = 'prompt_variables';

    protected $fillable = [
        'prompt_template_id',
        'prompt_version_id',
        'variable_name',
        'default_value',
        'is_required',
        'description',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'prompt_template_id' => 'integer',
            'prompt_version_id' => 'integer',
            'is_required' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }

}

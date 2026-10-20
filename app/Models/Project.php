<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use DomainException;

class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'repository_url',
        'status',
        'owner_user_id',
        'is_archived',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived'
            || $this->is_archived
            || $this->archived_at !== null;
    }

    public function canBePaused(): bool
    {
        return $this->status === 'active' && ! $this->isArchived();
    }

    public function canBeActivated(): bool
    {
        return $this->status === 'paused' && ! $this->isArchived();
    }

    public function canBeArchived(): bool
    {
        return in_array($this->status, ['active', 'paused'], true) && ! $this->isArchived();
    }

    public function canBeRestored(): bool
    {
        return $this->isArchived();
    }

    public function pause(): void
    {
        $this->applyLifecycleTransition('pause');
    }

    public function activate(): void
    {
        $this->applyLifecycleTransition('activate');
    }

    public function archive(): void
    {
        $this->applyLifecycleTransition('archive');
    }

    public function restore(): void
    {
        $this->applyLifecycleTransition('restore');
    }

    private function applyLifecycleTransition(string $transition): void
    {
        DB::transaction(function () use ($transition): void {
            $project = static::query()
                ->lockForUpdate()
                ->findOrFail($this->getKey());

            $attributes = match ($transition) {
                'pause' => $this->attributesForPause($project),
                'activate' => $this->attributesForActivation($project),
                'archive' => $this->attributesForArchive($project),
                'restore' => $this->attributesForRestore($project),
                default => throw new DomainException('Transição de ciclo de vida desconhecida.'),
            };

            foreach ($attributes as $attribute => $value) {
                $project->setAttribute($attribute, $value);
            }

            $project->saveOrFail();
        });

        $this->refresh();
    }

    /** @return array<string, mixed> */
    private function attributesForPause(self $project): array
    {
        if (! $project->canBePaused()) {
            throw new DomainException('Somente projetos ativos e não arquivados podem ser pausados.');
        }

        return ['status' => 'paused', 'is_archived' => false, 'archived_at' => null];
    }

    /** @return array<string, mixed> */
    private function attributesForActivation(self $project): array
    {
        if (! $project->canBeActivated()) {
            throw new DomainException('Somente projetos pausados e não arquivados podem ser ativados.');
        }

        return ['status' => 'active', 'is_archived' => false, 'archived_at' => null];
    }

    /** @return array<string, mixed> */
    private function attributesForArchive(self $project): array
    {
        if (! $project->canBeArchived()) {
            throw new DomainException('Somente projetos ativos ou pausados e não arquivados podem ser arquivados.');
        }

        return ['status' => 'archived', 'is_archived' => true, 'archived_at' => now()];
    }

    /** @return array<string, mixed> */
    private function attributesForRestore(self $project): array
    {
        if (! $project->canBeRestored()) {
            throw new DomainException('Somente projetos arquivados podem ser restaurados.');
        }

        return ['status' => 'active', 'is_archived' => false, 'archived_at' => null];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class, 'project_id');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class, 'project_id');
    }

    public function technologies(): HasMany
    {
        return $this->hasMany(ProjectTechnology::class, 'project_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'project_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'project_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id');
    }

    public function promptTemplates(): HasMany
    {
        return $this->hasMany(PromptTemplate::class, 'project_id');
    }

    public function technicalContexts(): HasMany
    {
        return $this->hasMany(TechnicalContext::class, 'project_id');
    }

    public function githubConnection(): HasOne
    {
        return $this->hasOne(GithubConnection::class, 'project_id');
    }

    public function githubQueryLogs(): HasMany
    {
        return $this->hasMany(GithubQueryLog::class, 'project_id');
    }

    public function assistantConversations(): HasMany
    {
        return $this->hasMany(AssistantConversation::class, 'project_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'project_id');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class, 'project_id');
    }
}

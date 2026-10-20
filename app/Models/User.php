<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password_hash',
        'status',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'password_hash' => 'hashed',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->status === 'active'
            && in_array($this->role?->slug, ['super-admin', 'admin'], true);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_user_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_user_id');
    }

    public function reportedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reporter_user_id');
    }

    public function taskComments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'user_id');
    }

    public function taskStatusChanges(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class, 'changed_by_user_id');
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class, 'user_id');
    }

    public function grantedProjectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class, 'granted_by_user_id');
    }

    public function revokedProjectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class, 'revoked_by_user_id');
    }

    public function createdDocumentVersions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'created_by_user_id');
    }

    public function updatedDocumentDrafts(): HasMany
    {
        return $this->hasMany(DocumentDraft::class, 'updated_by_user_id');
    }

    public function authoredDocumentChangeRequests(): HasMany
    {
        return $this->hasMany(DocumentChangeRequest::class, 'author_user_id');
    }

    public function documentChangeRequestDecisions(): HasMany
    {
        return $this->hasMany(DocumentChangeRequestDecision::class, 'decided_by_user_id');
    }

    public function createdPromptVersions(): HasMany
    {
        return $this->hasMany(PromptVersion::class, 'created_by_user_id');
    }

    public function promptFavorites(): HasMany
    {
        return $this->hasMany(PromptFavorite::class, 'user_id');
    }

    public function authoredPromptChangeRequests(): HasMany
    {
        return $this->hasMany(PromptChangeRequest::class, 'author_user_id');
    }

    public function promptChangeRequestDecisions(): HasMany
    {
        return $this->hasMany(PromptChangeRequestDecision::class, 'decided_by_user_id');
    }

    public function authoredSolutions(): HasMany
    {
        return $this->hasMany(Solution::class, 'author_user_id');
    }

    public function createdSolutionVersions(): HasMany
    {
        return $this->hasMany(SolutionVersion::class, 'created_by_user_id');
    }

    public function solutionValidations(): HasMany
    {
        return $this->hasMany(SolutionValidation::class, 'validated_by_user_id');
    }

    public function authoredSolutionChangeRequests(): HasMany
    {
        return $this->hasMany(SolutionChangeRequest::class, 'author_user_id');
    }

    public function solutionChangeRequestDecisions(): HasMany
    {
        return $this->hasMany(SolutionChangeRequestDecision::class, 'decided_by_user_id');
    }

    public function createdTechnicalContextVersions(): HasMany
    {
        return $this->hasMany(TechnicalContextVersion::class, 'created_by_user_id');
    }

    public function updatedTechnicalContextDrafts(): HasMany
    {
        return $this->hasMany(TechnicalContextDraft::class, 'updated_by_user_id');
    }

    public function authoredTechnicalContextChangeRequests(): HasMany
    {
        return $this->hasMany(TechnicalContextChangeRequest::class, 'author_user_id');
    }

    public function technicalContextChangeRequestDecisions(): HasMany
    {
        return $this->hasMany(TechnicalContextChangeRequestDecision::class, 'decided_by_user_id');
    }

    public function createdPromptTemplates(): HasMany
    {
        return $this->hasMany(PromptTemplate::class, 'created_by_user_id');
    }

    public function assistantConversations(): HasMany
    {
        return $this->hasMany(AssistantConversation::class, 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    public function authoredProblemDiagnoses(): HasMany
    {
        return $this->hasMany(ProblemDiagnosis::class, 'author_user_id');
    }

    public function reportedProblems(): HasMany
    {
        return $this->hasMany(Problem::class, 'reporter_user_id');
    }

    public function problemEventHistories(): HasMany
    {
        return $this->hasMany(ProblemEventHistory::class, 'user_id');
    }

}

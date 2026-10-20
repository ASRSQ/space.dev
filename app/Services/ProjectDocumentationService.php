<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChangeRequest;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\TechnicalContext;
use App\Models\TechnicalContextChangeRequest;
use App\Models\TechnicalContextVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectDocumentationService
{
    /** @return array<string, array{label: string, type: string}> */
    public function sections(): array
    {
        return config('project_documentation.sections', []);
    }

    public function saveDraft(Project $project, string $sectionKey, array $content, User $actor): Document|TechnicalContext
    {
        $section = $this->section($sectionKey);
        $this->authorizeActor($project, 'edit-project-documentation', $actor);

        return DB::transaction(function () use ($project, $sectionKey, $section, $content, $actor): Document|TechnicalContext {
            $project = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            if ($section['type'] === 'technical_context') {
                $this->validateTechnicalDraft($content);
                $context = $this->technicalContextFor($project, $sectionKey, $section['label']);
                $context = TechnicalContext::query()->lockForUpdate()->findOrFail($context->getKey());

                if ($context->changeRequests()->where('status', 'pending')->exists()) {
                    throw new DomainException('Esta seção está em revisão e não pode ser editada até a decisão.');
                }

                $context->draft()->updateOrCreate([], [
                    'architecture_summary' => (string) ($content['architecture_summary'] ?? ''),
                    'tech_stack_json' => $content['tech_stack_json'] ?? null,
                    'conventions_markdown' => $content['conventions_markdown'] ?? null,
                    'updated_by_user_id' => $actor->getKey(),
                ]);

                return $context->fresh(['draft', 'currentVersion']);
            }

            if (! isset($content['content_markdown']) || ! is_string($content['content_markdown'])) {
                throw new DomainException('O conteúdo do rascunho deve ser texto Markdown.');
            }

            $document = $this->documentFor($project, $sectionKey, $section['label']);
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->changeRequests()->where('status', 'pending')->exists()) {
                throw new DomainException('Esta seção está em revisão e não pode ser editada até a decisão.');
            }

            $document->draft()->updateOrCreate([], [
                'content_markdown' => (string) ($content['content_markdown'] ?? ''),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            return $document->fresh(['draft', 'currentVersion']);
        });
    }

    public function submitForReview(Project $project, string $sectionKey, string $reason, User $actor): DocumentChangeRequest|TechnicalContextChangeRequest
    {
        $section = $this->section($sectionKey);
        $this->authorizeActor($project, 'submit-project-documentation', $actor);

        if (trim($reason) === '' || mb_strlen($reason) > 5000) {
            throw new DomainException('Informe um motivo de revisão de até 5000 caracteres.');
        }

        return DB::transaction(function () use ($project, $sectionKey, $section, $reason, $actor): DocumentChangeRequest|TechnicalContextChangeRequest {
            if ($section['type'] === 'technical_context') {
                $context = $this->requireTechnicalContext($project, $sectionKey);
                $context = TechnicalContext::query()->lockForUpdate()->findOrFail($context->getKey());

                if ($context->changeRequests()->where('status', 'pending')->exists()) {
                    throw new DomainException('Já existe uma solicitação pendente para esta seção.');
                }

                $draft = $context->draft;
                if (! $draft || trim($draft->architecture_summary) === '') {
                    throw new DomainException('Salve um rascunho de arquitetura antes de enviá-lo para revisão.');
                }

                return $context->changeRequests()->create([
                    'proposed_version_number' => ((int) $context->versions()->max('version_number')) + 1,
                    'proposed_architecture_summary' => $draft->architecture_summary,
                    'proposed_tech_stack_json' => $draft->tech_stack_json,
                    'proposed_conventions_markdown' => $draft->conventions_markdown,
                    'reason' => $reason,
                    'status' => 'pending',
                    'author_user_id' => $actor->getKey(),
                ]);
            }

            $document = $this->requireDocument($project, $sectionKey);
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->changeRequests()->where('status', 'pending')->exists()) {
                throw new DomainException('Já existe uma solicitação pendente para esta seção.');
            }

            $draft = $document->draft;
            if (! $draft || trim($draft->content_markdown) === '') {
                throw new DomainException('Salve um rascunho de conteúdo antes de enviá-lo para revisão.');
            }

            return $document->changeRequests()->create([
                'proposed_version_number' => ((int) $document->versions()->max('version_number')) + 1,
                'proposed_title' => $document->title,
                'proposed_content' => $draft->content_markdown,
                'reason' => $reason,
                'status' => 'pending',
                'author_user_id' => $actor->getKey(),
            ]);
        });
    }

    public function decideDocument(Project $project, string $sectionKey, int $requestId, string $decision, string $comments, User $actor): DocumentVersion|null
    {
        if (($this->section($sectionKey)['type'] ?? null) !== 'document') {
            throw new DomainException('A seção informada não usa documentos Markdown.');
        }

        return $this->decide($project, $sectionKey, $requestId, $decision, $comments, $actor, 'document');
    }

    public function decideTechnicalContext(Project $project, string $sectionKey, int $requestId, string $decision, string $comments, User $actor): TechnicalContextVersion|null
    {
        if (($this->section($sectionKey)['type'] ?? null) !== 'technical_context') {
            throw new DomainException('A seção informada não usa contexto técnico.');
        }

        return $this->decide($project, $sectionKey, $requestId, $decision, $comments, $actor, 'technical_context');
    }

    /** @return array<string, mixed> */
    public function sectionState(Project $project, string $sectionKey): array
    {
        Gate::authorize('view-project-documentation', $project);
        $section = $this->section($sectionKey);

        if ($section['type'] === 'technical_context') {
            $entity = $this->findTechnicalContext($project, $sectionKey);
        } else {
            $entity = $this->findDocument($project, $sectionKey);
        }

        if (! $entity) {
            return ['status' => 'pending', 'published' => null, 'draft' => null, 'requests' => collect()];
        }

        $entity->load(['currentVersion', 'draft', 'changeRequests' => fn ($query) => $query->with('decisions.decidedByUser')->latest()]);
        $hasPending = $entity->changeRequests->contains(fn ($request): bool => $request->status === 'pending');
        $status = $hasPending ? 'review' : ($entity->draft ? 'draft' : ($entity->currentVersion ? 'published' : 'pending'));

        return [
            'status' => $status,
            'published' => $entity->currentVersion,
            'draft' => $entity->draft,
            'requests' => $entity->changeRequests,
            'entity' => $entity,
            'versions' => $entity->versions()->with('createdByUser')->orderByDesc('version_number')->get(),
        ];
    }

    private function decide(Project $project, string $sectionKey, int $requestId, string $decision, string $comments, User $actor, string $type): DocumentVersion|TechnicalContextVersion|null
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainException('A decisão deve ser aprovação ou rejeição.');
        }

        if (trim($comments) === '' || mb_strlen($comments) > 5000) {
            throw new DomainException('Informe um comentário de decisão de até 5000 caracteres.');
        }

        $this->section($sectionKey);
        $this->authorizeActor($project, 'review-project-documentation', $actor);

        return DB::transaction(function () use ($project, $sectionKey, $requestId, $decision, $comments, $actor, $type): DocumentVersion|TechnicalContextVersion|null {
            if ($type === 'technical_context') {
                $context = $this->requireTechnicalContext($project, $sectionKey);
                $context = TechnicalContext::query()->lockForUpdate()->findOrFail($context->getKey());
                $request = $context->changeRequests()->lockForUpdate()->findOrFail($requestId);
                if ($request->status !== 'pending') {
                    throw new DomainException('A solicitação já foi decidida.');
                }

                $version = null;
                if ($decision === 'approved') {
                    $versionNumber = ((int) $context->versions()->max('version_number')) + 1;
                    $request->proposed_version_number = $versionNumber;
                    $version = $context->versions()->create([
                        'version_number' => $versionNumber,
                        'architecture_summary' => $request->proposed_architecture_summary,
                        'tech_stack_json' => $request->proposed_tech_stack_json,
                        'conventions_markdown' => $request->proposed_conventions_markdown,
                        'changelog' => $request->reason,
                        'created_by_user_id' => $actor->getKey(),
                    ]);
                    $context->current_version_id = $version->getKey();
                    $context->saveOrFail();
                }

                $request->status = $decision;
                $request->saveOrFail();
                $request->decisions()->create([
                    'decided_by_user_id' => $actor->getKey(),
                    'decision' => $decision,
                    'comments' => $comments,
                    'decided_at' => now(),
                ]);

                if ($decision === 'approved') {
                    $context->draft()->delete();
                }

                return $version;
            }

            $document = $this->requireDocument($project, $sectionKey);
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
            $request = $document->changeRequests()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new DomainException('A solicitação já foi decidida.');
            }

            $version = null;
            if ($decision === 'approved') {
                $versionNumber = ((int) $document->versions()->max('version_number')) + 1;
                $request->proposed_version_number = $versionNumber;
                $version = $document->versions()->create([
                    'version_number' => $versionNumber,
                    'title' => $request->proposed_title,
                    'content_markdown' => $request->proposed_content,
                    'changelog' => $request->reason,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $document->current_version_id = $version->getKey();
                $document->saveOrFail();
            }

            $request->status = $decision;
            $request->saveOrFail();
            $request->decisions()->create([
                'decided_by_user_id' => $actor->getKey(),
                'decision' => $decision,
                'comments' => $comments,
                'decided_at' => now(),
            ]);

            if ($decision === 'approved') {
                $document->draft()->delete();
            }

            return $version;
        });
    }

    private function authorizeActor(Project $project, string $ability, User $actor): void
    {
        Gate::authorize($ability, $project);

        $authenticatedUser = auth()->user();

        if (! $authenticatedUser instanceof User || ! $authenticatedUser->is($actor)) {
            throw new AuthorizationException('The documentation actor must be the authenticated user.');
        }
    }

    private function documentFor(Project $project, string $sectionKey, string $label): Document
    {
        return $project->documents()->firstOrCreate(
            ['section_key' => $sectionKey],
            ['title' => $label, 'slug' => $this->availableSlug($project, $sectionKey)],
        );
    }

    private function technicalContextFor(Project $project, string $sectionKey, string $label): TechnicalContext
    {
        return $project->technicalContexts()->firstOrCreate(
            ['section_key' => $sectionKey],
            ['title' => $label, 'slug' => $this->availableSlug($project, $sectionKey, true)],
        );
    }

    private function availableSlug(Project $project, string $sectionKey, bool $technicalContext = false): string
    {
        $query = $technicalContext ? $project->technicalContexts() : $project->documents();
        $base = 'documentation-'.$sectionKey;
        $slug = $base;
        $suffix = 1;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function findDocument(Project $project, string $sectionKey): ?Document
    {
        return $project->documents()->where('section_key', $sectionKey)->first();
    }

    private function findTechnicalContext(Project $project, string $sectionKey): ?TechnicalContext
    {
        return $project->technicalContexts()->where('section_key', $sectionKey)->first();
    }

    private function requireDocument(Project $project, string $sectionKey): Document
    {
        return $this->findDocument($project, $sectionKey)
            ?? throw (new ModelNotFoundException())->setModel(Document::class);
    }

    private function requireTechnicalContext(Project $project, string $sectionKey): TechnicalContext
    {
        return $this->findTechnicalContext($project, $sectionKey)
            ?? throw (new ModelNotFoundException())->setModel(TechnicalContext::class);
    }

    /** @param array<string, mixed> $content */
    private function validateTechnicalDraft(array $content): void
    {
        if (! isset($content['architecture_summary']) || ! is_string($content['architecture_summary'])) {
            throw new DomainException('O resumo de arquitetura deve ser texto Markdown.');
        }

        $stack = $content['tech_stack_json'] ?? null;
        if ($stack !== null && ! is_array($stack)) {
            throw new DomainException('A lista de tecnologias deve ser um objeto JSON.');
        }

        foreach ($stack ?? [] as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new DomainException('Cada tecnologia precisa ter uma chave e um valor de texto.');
            }
        }

        foreach (['conventions_markdown'] as $field) {
            if (isset($content[$field]) && ! is_string($content[$field])) {
                throw new DomainException('As convenções devem ser texto Markdown.');
            }
        }
    }

    /** @return array{label: string, type: string} */
    private function section(string $sectionKey): array
    {
        $section = $this->sections()[$sectionKey] ?? null;
        if (! is_array($section)) {
            throw new DomainException('A seção de documentação informada não existe.');
        }

        return $section;
    }
}

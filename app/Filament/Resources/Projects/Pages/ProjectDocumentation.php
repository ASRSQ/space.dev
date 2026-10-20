<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\DocumentDraft;
use App\Models\Project;
use App\Models\TechnicalContextDraft;
use App\Services\ProjectDocumentationService;
use DomainException;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ProjectDocumentation extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-documentation';

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $selectedSection = 'overview';

    public ?int $viewingVersionId = null;

    public string $activeTab = 'read';

    public bool $previewDraft = false;

    public array $draftData = [];

    #[Locked]
    public array $savedDraftData = [];

    public string $reviewReason = '';

    public string $decisionComments = '';

    public function getHeading(): string
    {
        return 'Central de documentação';
    }

    public function getTitle(): string
    {
        return 'Documentação · '.$this->getRecord()->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Documentação';
    }

    public function draftForm(Schema $schema): Schema
    {
        $architecture = $this->selectedSection === 'architecture';

        return $schema
            ->statePath('draftData')
            ->operation('edit')
            ->columns(1)
            ->disabled(fn (): bool => $this->selectedState()['status'] === 'review'
                || Gate::denies('edit-project-documentation', $this->project()))
            ->components($architecture ? [
                MarkdownEditor::make('architectureSummary')
                    ->label('Resumo de arquitetura')->required()->rules(['string'])->fileAttachments(false)->minHeight('20rem')
                    ->commonMarkOptions(['html_input' => 'strip', 'allow_unsafe_links' => false]),
                Textarea::make('techStackText')->label('Tecnologias')
                    ->helperText('Uma tecnologia por linha, no formato chave: valor.')->rules(['nullable', 'string'])->rows(4),
                MarkdownEditor::make('conventionsMarkdown')
                    ->label('Convenções em Markdown')->rules(['nullable', 'string'])->fileAttachments(false)->minHeight('14rem')
                    ->commonMarkOptions(['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ] : [
                MarkdownEditor::make('draftContent')
                    ->label('Conteúdo Markdown')->required()->rules(['string'])->fileAttachments(false)->minHeight('24rem')
                    ->commonMarkOptions(['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ]);
    }

    public function reviewForm(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Textarea::make('reviewReason')->label('Motivo da alteração')
                ->required()->maxLength(5000)->rows(3),
        ]);
    }

    public function decisionForm(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Textarea::make('decisionComments')->label('Comentário da decisão')
                ->required()->maxLength(5000)->rows(3),
        ]);
    }

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['read', 'draft', 'approvals', 'history'], true), 404);
        $this->activeTab = $tab;
    }

    /** Only presents a preview; it never saves or publishes content. */
    public function toggleDraftPreview(): void
    {
        $this->previewDraft = ! $this->previewDraft;
    }

    public function previewTechnologies(): array
    {
        $technologies = [];
        foreach (preg_split('/\R/', $this->draftData['techStackText'] ?? '') ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = array_map('trim', explode(':', $line, 2));
                if ($key !== '') {
                    $technologies[$key] = $value;
                }
            }
        }

        return $technologies;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        Gate::authorize('view-project-documentation', $this->project());
        $this->loadSectionDraft();
    }

    public function hydrate(): void
    {
        parent::hydrate();
        Gate::authorize('view-project-documentation', $this->project());
    }

    public function selectSection(string $sectionKey): void
    {
        abort_unless(array_key_exists($sectionKey, app(ProjectDocumentationService::class)->sections()), 404);
        Gate::authorize('view-project-documentation', $this->project());
        $this->selectedSection = $sectionKey;
        $this->viewingVersionId = null;
        $this->activeTab = 'read';
        $this->previewDraft = false;
        $this->reviewReason = '';
        $this->decisionComments = '';
        $this->resetValidation();
        $this->cacheSchema('draftForm', null);
        $this->loadSectionDraft();
        $this->dispatch('documentation-section-changed');
    }

    public function saveDraft(): void
    {
        Gate::authorize('edit-project-documentation', $this->project());

        $service = app(ProjectDocumentationService::class);
        $section = $service->sections()[$this->selectedSection] ?? null;
        abort_unless($section, 404);

        try {
            // Let the service enforce its review lock even if a stale editor submits.
            $data = $this->selectedState()['status'] === 'review'
                ? $this->draftData
                : $this->draftForm->getState();
            if ($section['type'] === 'technical_context') {
                $service->saveDraft($this->project(), $this->selectedSection, [
                    'architecture_summary' => $data['architectureSummary'],
                    'tech_stack_json' => $this->parseTechStack($data['techStackText'] ?? ''),
                    'conventions_markdown' => $data['conventionsMarkdown'] ?? '',
                ], auth()->user());
            } else {
                $service->saveDraft($this->project(), $this->selectedSection, [
                    'content_markdown' => $data['draftContent'],
                ], auth()->user());
            }

            $this->savedDraftData = $this->draftData;
            Notification::make()->title('Rascunho salvo.')->success()->send();
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function submitForReview(): void
    {
        Gate::authorize('submit-project-documentation', $this->project());
        if ($this->draftData !== $this->savedDraftData) {
            throw ValidationException::withMessages(['reviewReason' => 'Salve as alterações do rascunho antes de enviar para revisão.']);
        }
        $this->validate(['reviewReason' => ['required', 'string', 'max:5000']]);

        try {
            app(ProjectDocumentationService::class)->submitForReview(
                $this->project(),
                $this->selectedSection,
                $this->reviewReason,
                auth()->user(),
            );
            $this->reviewReason = '';
            $this->activeTab = 'approvals';
            Notification::make()->title('Seção enviada para revisão.')->success()->send();
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function decide(int $requestId, string $decision): void
    {
        Gate::authorize('review-project-documentation', $this->project());
        $this->validate([
            'decisionComments' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $service = app(ProjectDocumentationService::class);
            $section = $service->sections()[$this->selectedSection] ?? null;
            abort_unless($section, 404);

            if ($section['type'] === 'technical_context') {
                $service->decideTechnicalContext($this->project(), $this->selectedSection, $requestId, $decision, $this->decisionComments, auth()->user());
            } else {
                $service->decideDocument($this->project(), $this->selectedSection, $requestId, $decision, $this->decisionComments, auth()->user());
            }

            $this->decisionComments = '';
            $this->loadSectionDraft();
            Notification::make()
                ->title($decision === 'approved' ? 'Conteúdo publicado.' : 'Proposta rejeitada; o rascunho foi preservado.')
                ->success()
                ->send();
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function sectionStates(): array
    {
        $service = app(ProjectDocumentationService::class);
        $states = [];
        foreach ($service->sections() as $key => $section) {
            $states[$key] = $section + $service->sectionState($this->project(), $key);
        }

        return $states;
    }

    public function selectedState(): array
    {
        return app(ProjectDocumentationService::class)->sectionState($this->project(), $this->selectedSection);
    }

    public function viewVersion(int $versionId): void
    {
        Gate::authorize('view-project-documentation', $this->project());
        $state = $this->selectedState();
        abort_unless(collect($state['versions'] ?? [])->contains(fn ($version): bool => (int) $version->getKey() === $versionId), 404);
        $this->viewingVersionId = $versionId;
        $this->activeTab = 'history';
    }

    public function historicalVersion(): ?Model
    {
        if ($this->viewingVersionId === null) {
            return null;
        }

        return collect($this->selectedState()['versions'] ?? [])->first(
            fn ($version): bool => (int) $version->getKey() === $this->viewingVersionId,
        );
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();
        Gate::authorize('view-project-documentation', $this->project());
    }

    private function project(): Project
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Project, 404);

        return $record;
    }

    private function loadSectionDraft(): void
    {
        $state = app(ProjectDocumentationService::class)->sectionState($this->project(), $this->selectedSection);
        $draft = $state['draft'];

        $data = $this->selectedSection === 'architecture'
            ? [
                'architectureSummary' => $draft instanceof TechnicalContextDraft ? $draft->architecture_summary : '',
                'techStackText' => $draft instanceof TechnicalContextDraft
                    ? collect($draft->tech_stack_json ?? [])->map(fn ($value, $key): string => "{$key}: {$value}")->implode("\n")
                    : '',
                'conventionsMarkdown' => $draft instanceof TechnicalContextDraft ? (string) $draft->conventions_markdown : '',
            ]
            : ['draftContent' => $draft instanceof DocumentDraft ? $draft->content_markdown : ''];
        $this->draftForm->fill($data);
        $this->savedDraftData = $this->draftData;
    }

    private function parseTechStack(string $value): ?array
    {
        $stack = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                throw ValidationException::withMessages(['draftData.techStackText' => 'Informe cada tecnologia no formato chave: valor.']);
            }

            [$key, $item] = array_map('trim', explode(':', $line, 2));
            if ($key === '' || $item === '') {
                throw ValidationException::withMessages(['draftData.techStackText' => 'Informe uma chave e um valor para cada tecnologia.']);
            }
            $stack[$key] = $item;
        }

        return $stack === [] ? null : $stack;
    }
}

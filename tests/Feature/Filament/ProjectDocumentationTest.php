<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Projects\Pages\ProjectDocumentation;
use App\Models\Document;
use App\Models\DocumentChangeRequest;
use App\Models\DocumentChangeRequestDecision;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Role;
use App\Models\TechnicalContext;
use App\Models\TechnicalContextChangeRequest;
use App\Models\TechnicalContextVersion;
use App\Models\User;
use App\Services\ProjectDocumentationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProjectDocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'), 'Documentation tests must never connect to MySQL.');
        $this->assertSame(':memory:', config('database.connections.sqlite.database'), 'Schema fixtures require disposable in-memory SQLite.');
        $this->createSchema();
    }

    public function test_nine_sections_are_project_scoped_and_start_pending(): void
    {
        [$admin, $project] = $this->createProject();
        $otherProject = $this->makeProject($admin, 'Other project');
        $service = app(ProjectDocumentationService::class);

        $this->actingAs($admin, 'web');
        $this->assertCount(9, $service->sections());
        $this->assertSame('pending', $service->sectionState($project, 'overview')['status']);

        $service->saveDraft($project, 'overview', ['content_markdown' => '# Project one'], $admin);

        $this->assertSame('draft', $service->sectionState($project, 'overview')['status']);
        $this->assertSame('pending', $service->sectionState($otherProject, 'overview')['status']);
        $this->assertDatabaseHas('documents', ['project_id' => $project->getKey(), 'section_key' => 'overview']);
        $this->assertDatabaseMissing('documents', ['project_id' => $otherProject->getKey(), 'section_key' => 'overview']);
    }

    public function test_legacy_null_section_keys_remain_valid_and_document_draft_does_not_publish(): void
    {
        [$admin, $project] = $this->createProject();
        Document::query()->create([
            'project_id' => $project->getKey(),
            'title' => 'Legacy one',
            'slug' => 'legacy-one',
        ]);
        Document::query()->create([
            'project_id' => $project->getKey(),
            'title' => 'Legacy two',
            'slug' => 'legacy-two',
        ]);

        $this->actingAs($admin, 'web');
        app(ProjectDocumentationService::class)->saveDraft($project, 'overview', ['content_markdown' => '# Draft only'], $admin);

        $document = Document::query()->where('section_key', 'overview')->sole();
        $this->assertNull($document->current_version_id);
        $this->assertSame('# Draft only', $document->draft->content_markdown);
        $this->assertSame('draft', app(ProjectDocumentationService::class)->sectionState($project, 'overview')['status']);
    }

    public function test_document_review_rejection_preserves_published_version_and_draft(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Initial'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Initial publication', $admin);
        $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Approved', $admin);

        $document = Document::query()->where('section_key', 'overview')->sole();
        $publishedId = $document->current_version_id;
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Rejected proposal'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Proposed change', $admin);
        $service->decideDocument($project, 'overview', $request->getKey(), 'rejected', 'Please revise', $admin);

        $this->assertSame($publishedId, $document->fresh()->current_version_id);
        $this->assertSame('# Rejected proposal', $document->fresh()->draft->content_markdown);
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseHas('document_change_request_decisions', [
            'change_request_id' => $request->getKey(),
            'decision' => 'rejected',
            'comments' => 'Please revise',
        ]);
        $this->assertSame('draft', $service->sectionState($project, 'overview')['status']);
    }

    public function test_approval_publishes_transactionally_preserves_history_and_clears_draft(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# First'], $admin);
        $firstRequest = $service->submitForReview($project, 'overview', 'First release', $admin);
        $firstVersion = $service->decideDocument($project, 'overview', $firstRequest->getKey(), 'approved', 'Looks good', $admin);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Second'], $admin);
        $secondRequest = $service->submitForReview($project, 'overview', 'Second release', $admin);
        $secondVersion = $service->decideDocument($project, 'overview', $secondRequest->getKey(), 'approved', 'Approved', $admin);

        $document = Document::query()->where('section_key', 'overview')->sole();
        $this->assertSame(1, $firstVersion->version_number);
        $this->assertSame(2, $secondVersion->version_number);
        $this->assertSame($secondVersion->getKey(), $document->current_version_id);
        $this->assertSame(2, $document->versions()->count());
        $this->assertNull($document->fresh()->draft);
        $this->assertSame('published', $service->sectionState($project, 'overview')['status']);
        $this->assertDatabaseHas('document_change_request_decisions', [
            'change_request_id' => $secondRequest->getKey(),
            'decision' => 'approved',
        ]);

        Filament::setCurrentPanel('admin');
        Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->assertSee('1 de 9 seções');
        Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('viewVersion', $firstVersion->getKey())
            ->assertSee('Versão 1')
            ->assertSee('First');
    }

    public function test_pending_request_is_unique_per_section_and_blocks_draft_edits(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Ready'], $admin);
        $service->submitForReview($project, 'overview', 'Review this', $admin);

        try {
            $service->submitForReview($project, 'overview', 'Duplicate', $admin);
            $this->fail('A second pending review was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Já existe', $exception->getMessage());
        }

        try {
            $service->saveDraft($project, 'overview', ['content_markdown' => '# Changed'], $admin);
            $this->fail('A draft was editable while its proposal was under review.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('em revisão', $exception->getMessage());
        }

        $this->assertSame(1, DocumentChangeRequest::query()->count());
    }

    public function test_database_constraint_rejects_a_second_pending_request_for_the_same_document(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Content'], $admin);
        $first = $service->submitForReview($project, 'overview', 'First', $admin);

        $this->expectException(QueryException::class);
        DocumentChangeRequest::query()->create([
            'document_id' => $first->document_id,
            'proposed_version_number' => 1,
            'proposed_title' => 'Duplicate pending',
            'proposed_content' => 'Duplicate',
            'reason' => 'Attempt to bypass the service',
            'status' => 'pending',
            'author_user_id' => $admin->getKey(),
        ]);
    }

    public function test_document_from_another_project_cannot_be_decided_through_this_project_scope(): void
    {
        [$admin, $project] = $this->createProject();
        $otherProject = $this->makeProject($admin, 'Other documentation project');
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($otherProject, 'overview', ['content_markdown' => '# Other'], $admin);
        $request = $service->submitForReview($otherProject, 'overview', 'Other project change', $admin);

        $this->expectException(ModelNotFoundException::class);
        $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Cross project attempt', $admin);
    }

    public function test_technical_context_from_another_project_cannot_be_decided_through_this_project_scope(): void
    {
        [$admin, $project] = $this->createProject();
        $otherProject = $this->makeProject($admin, 'Other architecture project');
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($otherProject, 'architecture', [
            'architecture_summary' => '# Other project architecture',
        ], $admin);
        $request = $service->submitForReview($otherProject, 'architecture', 'Other project change', $admin);

        $this->expectException(ModelNotFoundException::class);
        $service->decideTechnicalContext($project, 'architecture', $request->getKey(), 'approved', 'Cross project attempt', $admin);
    }

    public function test_cancelled_request_cannot_be_approved_later(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Draft'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Cancellation scenario', $admin);
        $request->status = 'cancelled';
        $request->saveOrFail();

        try {
            $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Late approval', $admin);
            $this->fail('A cancelled request was approved.');
        } catch (\DomainException) {
            $document = Document::query()->where('section_key', 'overview')->sole();
            $this->assertNull($document->current_version_id);
            $this->assertNotNull($document->fresh()->draft);
            $this->assertSame('cancelled', $request->fresh()->status);
            $this->assertSame(0, DocumentVersion::query()->count());
            $this->assertDatabaseCount('document_change_request_decisions', 0);
        }
    }

    public function test_database_constraint_rejects_a_second_pending_technical_context_request(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'architecture', ['architecture_summary' => '# Architecture'], $admin);
        $first = $service->submitForReview($project, 'architecture', 'First', $admin);

        $this->expectException(QueryException::class);
        TechnicalContextChangeRequest::query()->create([
            'technical_context_id' => $first->technical_context_id,
            'proposed_version_number' => 1,
            'proposed_architecture_summary' => 'Duplicate pending',
            'reason' => 'Attempt to bypass the service',
            'status' => 'pending',
            'author_user_id' => $admin->getKey(),
        ]);
    }

    public function test_technical_context_uses_its_own_version_and_review_tables(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'architecture', [
            'architecture_summary' => '# Application layers',
            'tech_stack_json' => ['framework' => 'Laravel'],
            'conventions_markdown' => 'Use services for domain flows.',
        ], $admin);

        $context = TechnicalContext::query()->where('section_key', 'architecture')->sole();
        $this->assertNull($context->current_version_id);
        $this->assertSame(['framework' => 'Laravel'], $context->draft->tech_stack_json);
        $request = $service->submitForReview($project, 'architecture', 'Architecture documentation', $admin);
        $version = $service->decideTechnicalContext($project, 'architecture', $request->getKey(), 'approved', 'Approved', $admin);

        $this->assertInstanceOf(TechnicalContextVersion::class, $version);
        $this->assertSame($version->getKey(), $context->fresh()->current_version_id);
        $this->assertSame('published', $service->sectionState($project, 'architecture')['status']);
        $this->assertSame(1, TechnicalContextChangeRequest::query()->count());
    }

    public function test_architecture_review_and_history_show_all_versioned_fields(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $service = app(ProjectDocumentationService::class);

        $service->saveDraft($project, 'architecture', [
            'architecture_summary' => '# Layered design',
            'tech_stack_json' => [
                'framework' => 'Laravel 12',
                'example' => '<script>alert(1)</script>',
                'injected' => '<img src=x onerror=alert(1)>',
            ],
            'conventions_markdown' => '**Use services** for domain workflows. <script>alert(1)</script> '
                .'[unsafe](javascript:alert(1)) <img src=x onerror=alert(1)>',
        ], $admin);
        $request = $service->submitForReview($project, 'architecture', 'Initial architecture', $admin);

        $proposalHtml = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')
            ->assertSee('Layered design')
            ->assertSee('framework')
            ->assertSee('Laravel 12')
            ->assertSee('Use services')
            ->html();
        $this->assertNoActiveArchitecturePayload($proposalHtml);
        $this->assertStringContainsString('&lt;script&gt;', $proposalHtml);
        $this->assertStringContainsString('&lt;img', $proposalHtml);

        $version = $service->decideTechnicalContext($project, 'architecture', $request->getKey(), 'approved', 'Approved', $admin);

        $historyHtml = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')
            ->call('viewVersion', $version->getKey())
            ->assertSee('Layered design')
            ->assertSee('framework')
            ->assertSee('Laravel 12')
            ->assertSee('Use services')
            ->html();
        $this->assertNoActiveArchitecturePayload($historyHtml);
        $this->assertStringContainsString('&lt;script&gt;', $historyHtml);
        $this->assertStringContainsString('&lt;img', $historyHtml);
    }

    public function test_architecture_review_handles_null_and_empty_optional_fields(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $service = app(ProjectDocumentationService::class);

        $service->saveDraft($project, 'architecture', [
            'architecture_summary' => '# Minimal architecture',
            'tech_stack_json' => null,
            'conventions_markdown' => '',
        ], $admin);
        $service->submitForReview($project, 'architecture', 'Minimal proposal', $admin);

        $html = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')
            ->assertSee('Minimal architecture')
            ->assertHasNoErrors()
            ->html();
        $this->assertNoActiveArchitecturePayload($html);
    }

    public function test_failure_during_version_creation_rolls_back_publication_and_keeps_draft(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Draft'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Rollback scenario', $admin);

        Event::listen('eloquent.created: '.DocumentVersion::class, function (): void {
            throw new RuntimeException('Controlled version write failure.');
        });

        try {
            $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Approve', $admin);
            $this->fail('The controlled failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled version write failure.', $exception->getMessage());
        }

        $document = Document::query()->where('section_key', 'overview')->sole();
        $this->assertNull($document->current_version_id);
        $this->assertNotNull($document->fresh()->draft);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, DocumentVersion::query()->count());
        $this->assertDatabaseCount('document_change_request_decisions', 0);
    }

    public function test_failure_after_pointer_and_request_updates_rolls_back_the_entire_publication(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Draft'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Late rollback scenario', $admin);

        Event::listen('eloquent.created: '.DocumentChangeRequestDecision::class, function (): void {
            throw new RuntimeException('Controlled decision write failure.');
        });

        try {
            $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Approve', $admin);
            $this->fail('The controlled decision failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled decision write failure.', $exception->getMessage());
        }

        $document = Document::query()->where('section_key', 'overview')->sole();
        $this->assertNull($document->fresh()->current_version_id);
        $this->assertNotNull($document->fresh()->draft);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, DocumentVersion::query()->count());
        $this->assertDatabaseCount('document_change_request_decisions', 0);
    }

    public function test_admin_authorization_is_checked_by_service_and_panel_page(): void
    {
        [$admin, $project] = $this->createProject();
        $developer = $this->makeUser('developer');
        $this->actingAs($developer, 'web');

        try {
            app(ProjectDocumentationService::class)->saveDraft($project, 'overview', ['content_markdown' => 'Denied'], $developer);
            $this->fail('A non-admin could save documentation.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('documents', 0);
        }

        try {
            app(ProjectDocumentationService::class)->sectionState($project, 'overview');
            $this->fail('A non-admin read documentation state through the service.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('documents', 0);
        }

        foreach ([
            fn () => app(ProjectDocumentationService::class)->submitForReview($project, 'overview', 'Denied', $developer),
            fn () => app(ProjectDocumentationService::class)->decideDocument($project, 'overview', 1, 'rejected', 'Denied', $developer),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A non-admin performed a review operation.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('documents', 0);
            }
        }

        Filament::setCurrentPanel('admin');
        $this->get('/admin/projects/'.$project->getKey().'/documentation')->assertForbidden();

        $this->actingAs($admin, 'web');
        $this->get('/admin/projects/'.$project->getKey().'/documentation')
            ->assertOk()
            ->assertSee('Central de documentação')
            ->assertSee('Esta seção ainda não possui conteúdo oficial publicado.')
            ->assertSee('0 de 9 seções');
    }

    public function test_service_rejects_an_actor_other_than_the_authenticated_administrator(): void
    {
        [$admin, $project] = $this->createProject();
        $otherAdmin = $this->makeUser('super-admin');
        $this->actingAs($admin, 'web');

        try {
            app(ProjectDocumentationService::class)->saveDraft(
                $project,
                'overview',
                ['content_markdown' => '# Spoofed actor'],
                $otherAdmin,
            );
            $this->fail('A different actor was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('documents', 0);
        }

        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Legitimate draft'], $admin);

        try {
            $service->submitForReview($project, 'overview', 'Spoofed author', $otherAdmin);
            $this->fail('A different author was accepted for review submission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('document_change_requests', 0);
        }

        $request = $service->submitForReview($project, 'overview', 'Legitimate submission', $admin);

        try {
            $service->decideDocument($project, 'overview', $request->getKey(), 'rejected', 'Spoofed reviewer', $otherAdmin);
            $this->fail('A different reviewer was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertDatabaseCount('document_change_request_decisions', 0);
        }
    }

    public function test_inactive_and_suspended_administrators_cannot_view_or_edit_documentation(): void
    {
        [$admin, $project] = $this->createProject();
        $inactive = $this->makeUser('admin', 'inactive');
        $suspended = $this->makeUser('super-admin', 'suspended');
        Filament::setCurrentPanel('admin');

        foreach ([$inactive, $suspended] as $user) {
            $this->actingAs($user, 'web');
            try {
                app(ProjectDocumentationService::class)->saveDraft($project, 'overview', ['content_markdown' => 'Denied'], $user);
                $this->fail('An inactive administrator saved documentation.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('documents', 0);
            }

            $this->get('/admin/projects/'.$project->getKey().'/documentation')->assertForbidden();
        }

        $this->actingAs($admin, 'web');
    }

    public function test_page_saves_a_draft_and_updates_state_without_publishing(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->set('draftData.draftContent', '# Draft from interface')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $document = Document::query()->where('section_key', 'overview')->sole();
        $this->assertNull($document->current_version_id);
        $this->assertSame('# Draft from interface', $document->draft->content_markdown);
    }

    private function assertNoActiveArchitecturePayload(string $html): void
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertSame(0, $document->getElementsByTagName('script')->length, 'Rendered architecture output contains an active script element.');
        $this->assertSame(0, $document->getElementsByTagName('img')->length, 'Rendered architecture output contains an active image element.');

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                $this->assertFalse(str_starts_with(strtolower($attribute->name), 'on'), 'Rendered architecture output contains an active event attribute.');
                $this->assertFalse(
                    $attribute->name === 'href' && preg_match('/^\s*javascript:/i', $attribute->value) === 1,
                    'Rendered architecture output contains a javascript link.'
                );
            }
        }
    }

    public function test_draft_schema_is_editable_and_separate_from_record_form(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()]);
        $this->assertSame('draftData', $page->instance()->draftForm->getStatePath());
        $this->assertFalse($page->instance()->draftForm->isDisabled());
        $this->assertTrue($page->instance()->form->isDisabled());
        $page->assertSee('Leitura')->assertSee('Aprovações')->assertSee('Histórico')
            ->call('selectTab', 'draft')
            ->set('draftData.draftContent', '# Interface draft')
            ->call('toggleDraftPreview')->assertSee('Interface draft')
            ->call('saveDraft')->assertHasNoErrors();
        $this->assertSame(0, DocumentVersion::query()->count());
        $this->assertSame('# Interface draft', Document::query()->sole()->draft->content_markdown);
    }

    public function test_editor_preserves_architecture_fields_and_validates_stack(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')
            ->set('draftData.architectureSummary', '# Architecture draft')
            ->set('draftData.techStackText', 'invalid format')
            ->set('draftData.conventionsMarkdown', '**Use services**')
            ->call('saveDraft')->assertHasErrors(['draftData.techStackText'])
            ->set('draftData.techStackText', "framework: Laravel 12\nui: Filament 5")
            ->call('saveDraft')->assertHasNoErrors();
        $draft = TechnicalContext::query()->sole()->draft;
        $this->assertSame('# Architecture draft', $draft->architecture_summary);
        $this->assertSame(['framework' => 'Laravel 12', 'ui' => 'Filament 5'], $draft->tech_stack_json);
        $this->assertSame('**Use services**', $draft->conventions_markdown);
        $page->call('selectSection', 'overview')->assertSet('draftData.draftContent', '')
            ->call('selectSection', 'architecture')->assertSet('draftData.architectureSummary', '# Architecture draft');
        $this->assertSame(0, TechnicalContextVersion::query()->count());
    }

    public function test_interface_review_rejection_and_approval_keep_existing_flow(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->set('draftData.draftContent', '# Ready')->call('saveDraft')->assertHasNoErrors()
            ->set('reviewReason', 'First release')->call('submitForReview')->assertHasNoErrors()
            ->assertSet('activeTab', 'approvals')->assertSee('Alteração em revisão');
        $request = DocumentChangeRequest::query()->sole();
        $page->set('draftData.draftContent', '# Stale edit')->call('saveDraft');
        $this->assertSame('# Ready', Document::query()->sole()->draft->content_markdown);
        $page->set('decisionComments', 'Please improve')->call('decide', $request->getKey(), 'rejected')->assertHasNoErrors()
            ->assertSet('draftData.draftContent', '# Ready')
            ->set('reviewReason', 'Resubmit')->call('submitForReview')->assertHasNoErrors();
        $request = DocumentChangeRequest::query()->where('status', 'pending')->sole();
        $page->set('decisionComments', 'Approved')->call('decide', $request->getKey(), 'approved')->assertHasNoErrors();
        $this->assertSame(1, DocumentVersion::query()->count());
        $this->assertNull(Document::query()->sole()->draft);
        $page->call('viewVersion', DocumentVersion::query()->sole()->getKey())->assertSet('activeTab', 'history')->assertSee('Ready');
    }

    public function test_preview_is_safe_and_unsaved_content_cannot_be_submitted(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->set('draftData.draftContent', '# Saved')->call('saveDraft')
            ->set('draftData.draftContent', '<script>alert(1)</script> [bad](javascript:alert(1))')
            ->call('toggleDraftPreview')->assertSee('Prévia do rascunho')
            ->set('reviewReason', 'Unsaved change')->call('submitForReview')->assertHasErrors(['reviewReason']);
        $document = new \DOMDocument;
        @$document->loadHTML($page->html());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//*[@id="docs-draft-preview"]//script')->length);
        $this->assertSame(0, $xpath->query('//*[@id="docs-draft-preview"]//a[starts-with(@href,"javascript:")]')->length);
        $this->assertSame(0, DocumentChangeRequest::query()->count());
        $this->assertSame(0, DocumentVersion::query()->count());
    }

    public function test_navigation_follows_configuration_and_tabs_have_accessible_relationships(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()]);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        $labels = [];
        foreach ($xpath->query('//*[@id="docs-section-navigation"]//span[contains(@class,"docs-nav-label")]') as $node) {
            $labels[] = trim($node->textContent);
        }
        $this->assertSame(array_column(config('project_documentation.sections'), 'label'), $labels);
        $this->assertSame(4, $xpath->query('//*[@role="tab"]')->length);
        $this->assertSame(4, $xpath->query('//*[@role="tabpanel"]')->length);
        $this->assertStringNotContainsString('@js(', $page->html());
        $page->call('selectSection', 'unknown')->assertNotFound();
    }

    public function test_ui_two_publications_preserve_prior_reader_and_history(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        foreach ([1, 2] as $number) {
            $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
                ->set('draftData.draftContent', '# Synthetic version '.$number)
                ->call('saveDraft')->assertHasNoErrors();
            $this->assertSame($number - 1, DocumentVersion::query()->count());
            if ($number === 2) {
                $page->assertSee('Synthetic version 1');
                $this->assertSame('# Synthetic version 1', Document::query()->sole()->currentVersion->content_markdown);
            }
            $page->set('reviewReason', 'Synthetic release '.$number)->call('submitForReview')->assertHasNoErrors();
            $request = DocumentChangeRequest::query()->where('status', 'pending')->sole();
            $page->set('decisionComments', 'Explicit synthetic approval')->call('decide', $request->getKey(), 'approved')->assertHasNoErrors();
            $this->assertNull(Document::query()->sole()->draft);
            $this->assertSame($number, DocumentVersion::query()->count());
        }
        $versions = DocumentVersion::query()->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()]);
        $page->assertSee('Synthetic version 2')->assertSee('1 de 9 seções');
        foreach ($versions as $version) {
            $page->call('viewVersion', $version->getKey())->assertSet('activeTab', 'history')
                ->assertSee('Synthetic version '.$version->version_number);
        }
        $this->assertSame('# Synthetic version 1', $versions->first()->fresh()->content_markdown);
    }

    public function test_architecture_ui_roundtrip_preserves_three_fields_in_proposal_and_version(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')
            ->set('draftData.architectureSummary', '# Synthetic architecture')
            ->set('draftData.techStackText', "framework: Laravel 12\nui: Filament 5")
            ->set('draftData.conventionsMarkdown', '**Synthetic conventions**')
            ->call('saveDraft')->assertHasNoErrors();
        $this->assertSame(0, TechnicalContextVersion::query()->count());
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->call('selectSection', 'architecture')->assertSet('draftData.architectureSummary', '# Synthetic architecture')
            ->assertSet('draftData.conventionsMarkdown', '**Synthetic conventions**')
            ->set('reviewReason', 'Synthetic architecture review')->call('submitForReview')->assertHasNoErrors();
        $request = TechnicalContextChangeRequest::query()->sole();
        $this->assertSame(['framework' => 'Laravel 12', 'ui' => 'Filament 5'], $request->proposed_tech_stack_json);
        $this->assertSame('# Synthetic architecture', $request->proposed_architecture_summary);
        $this->assertSame('**Synthetic conventions**', $request->proposed_conventions_markdown);
        $page->set('draftData.architectureSummary', '# Forbidden stale editor')->call('saveDraft');
        $this->assertSame('# Synthetic architecture', TechnicalContext::query()->sole()->draft->architecture_summary);
        $page->set('decisionComments', 'Approved synthetic architecture')->call('decide', $request->getKey(), 'approved')->assertHasNoErrors();
        $version = TechnicalContextVersion::query()->sole();
        $this->assertSame($request->proposed_architecture_summary, $version->architecture_summary);
        $this->assertSame($request->proposed_tech_stack_json, $version->tech_stack_json);
        $this->assertSame($request->proposed_conventions_markdown, $version->conventions_markdown);
        $this->assertNull(TechnicalContext::query()->sole()->draft);
        $page->call('viewVersion', $version->getKey())->assertSee('Synthetic conventions')->assertSee('Laravel 12');
    }

    public function test_page_rejects_history_from_another_project(): void
    {
        [$admin, $project] = $this->createProject();
        $otherProject = $this->makeProject($admin, 'Synthetic project B');
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Project A only'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Synthetic scope check', $admin);
        $version = $service->decideDocument($project, 'overview', $request->getKey(), 'approved', 'Explicit approval', $admin);
        Livewire::test(ProjectDocumentation::class, ['record' => $otherProject->getKey()])
            ->assertDontSee('Project A only')->call('viewVersion', $version->getKey())->assertNotFound();
        $this->assertDatabaseMissing('documents', ['project_id' => $otherProject->getKey()]);
    }

    public function test_empty_decision_comment_cannot_approve_or_reject(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $service = app(ProjectDocumentationService::class);
        $service->saveDraft($project, 'overview', ['content_markdown' => '# Synthetic pending proposal'], $admin);
        $request = $service->submitForReview($project, 'overview', 'Synthetic review', $admin);
        foreach (['approved', 'rejected'] as $decision) {
            Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
                ->set('decisionComments', '')->call('decide', $request->getKey(), $decision)->assertHasErrors(['decisionComments']);
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertDatabaseCount('document_versions', 0);
            $this->assertDatabaseCount('document_change_request_decisions', 0);
        }
    }

    public function test_switching_tabs_retains_unsaved_editor_and_review_reason(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()])
            ->set('draftData.draftContent', '# Unsaved synthetic content')->set('reviewReason', 'Unsaved synthetic reason');
        foreach (['history', 'approvals', 'read', 'draft'] as $tab) {
            $page->call('selectTab', $tab)->assertSet('activeTab', $tab)
                ->assertSet('draftData.draftContent', '# Unsaved synthetic content')
                ->assertSet('reviewReason', 'Unsaved synthetic reason');
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_drafts', 0);
    }

    public function test_all_nine_sections_can_be_selected_without_creating_data(): void
    {
        [$admin, $project] = $this->createProject();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(ProjectDocumentation::class, ['record' => $project->getKey()]);
        foreach (config('project_documentation.sections') as $key => $section) {
            $page->call('selectSection', $key)->assertSet('selectedSection', $key)
                ->assertSet('activeTab', 'read')->assertSee($section['label'])->assertHasNoErrors();
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('technical_contexts', 0);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
            $table->foreign('role_id')->references('id')->on('roles');
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('owner_user_id');
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('owner_user_id')->references('id')->on('users');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 200);
            $table->string('slug', 200);
            $table->string('section_key', 64)->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
            $table->unique(['project_id', 'section_key']);
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign(['current_version_id', 'id'])->references(['id', 'document_id'])->on('document_versions');
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('title', 200);
            $table->mediumText('content_markdown');
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number']);
            $table->unique(['id', 'document_id']);
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });

        Schema::create('document_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('proposed_version_number');
            $table->string('proposed_title', 200);
            $table->mediumText('proposed_content');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('pending_document_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN document_id ELSE NULL END");
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
            $table->unique('pending_document_id');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('author_user_id')->references('id')->on('users');
        });

        Schema::create('document_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('comments');
            $table->dateTime('decided_at')->useCurrent();
            $table->foreign('change_request_id')->references('id')->on('document_change_requests');
            $table->foreign('decided_by_user_id')->references('id')->on('users');
        });

        Schema::create('document_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id')->unique();
            $table->mediumText('content_markdown');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users');
        });

        Schema::create('technical_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('title', 150);
            $table->string('slug', 150);
            $table->string('section_key', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
            $table->unique(['project_id', 'section_key']);
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign(['current_version_id', 'id'])->references(['id', 'technical_context_id'])->on('technical_context_versions');
        });

        Schema::create('technical_context_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('technical_context_id');
            $table->unsignedInteger('version_number');
            $table->mediumText('architecture_summary');
            $table->json('tech_stack_json')->nullable();
            $table->mediumText('conventions_markdown')->nullable();
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['technical_context_id', 'version_number']);
            $table->unique(['id', 'technical_context_id']);
            $table->foreign('technical_context_id')->references('id')->on('technical_contexts');
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });

        Schema::create('technical_context_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('technical_context_id');
            $table->unsignedInteger('proposed_version_number');
            $table->mediumText('proposed_architecture_summary');
            $table->json('proposed_tech_stack_json')->nullable();
            $table->mediumText('proposed_conventions_markdown')->nullable();
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('pending_technical_context_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN technical_context_id ELSE NULL END");
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
            $table->unique('pending_technical_context_id');
            $table->foreign('technical_context_id')->references('id')->on('technical_contexts');
            $table->foreign('author_user_id')->references('id')->on('users');
        });

        Schema::create('technical_context_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('comments');
            $table->dateTime('decided_at')->useCurrent();
            $table->foreign('change_request_id')->references('id')->on('technical_context_change_requests');
            $table->foreign('decided_by_user_id')->references('id')->on('users');
        });

        Schema::create('technical_context_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('technical_context_id')->unique();
            $table->mediumText('architecture_summary');
            $table->json('tech_stack_json')->nullable();
            $table->mediumText('conventions_markdown')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->foreign('technical_context_id')->references('id')->on('technical_contexts')->cascadeOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users');
        });
    }

    /** @return array{0: User, 1: Project} */
    private function createProject(): array
    {
        $admin = $this->makeUser('admin');

        return [$admin, $this->makeProject($admin, 'Documentation project')];
    }

    private function makeUser(string $roleSlug, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);

        return User::query()->create([
            'role_id' => $role->getKey(),
            'name' => ucfirst($roleSlug),
            'email' => $roleSlug.'-'.bin2hex(random_bytes(5)).'@example.test',
            'password_hash' => 'test-password',
            'status' => $status,
        ]);
    }

    private function makeProject(User $owner, string $name): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.bin2hex(random_bytes(4)),
            'status' => 'active',
            'owner_user_id' => $owner->getKey(),
        ]);
    }
}

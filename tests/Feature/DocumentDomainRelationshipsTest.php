<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentChangeRequest;
use App\Models\DocumentChangeRequestDecision;
use App\Models\DocumentTag;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentDomainRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->unsignedBigInteger('owner_user_id');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['current_version_id', 'id'])
                ->references(['id', 'document_id'])
                ->on('document_versions');
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->text('content_markdown');
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('document_id')->references('id')->on('documents');
            $table->unique(['document_id', 'version_number']);
            $table->unique(['id', 'document_id']);
        });

        Schema::create('document_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('proposed_version_number');
            $table->string('proposed_title');
            $table->text('proposed_content');
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
        });

        Schema::create('document_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->string('decision');
            $table->text('comments');
            $table->timestamp('decided_at')->useCurrent();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('document_tags', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['document_id', 'tag_id']);
        });
    }

    public function test_document_current_version_and_version_history_relations(): void
    {
        [$user, $project, $category, $document] = $this->createDocumentContext();
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'title' => 'Architecture',
            'content_markdown' => '# Architecture',
            'created_by_user_id' => $user->id,
        ]);
        $document->current_version_id = $version->id;
        $document->save();

        $this->assertSame($project->id, $document->project->id);
        $this->assertSame($category->id, $document->category->id);
        $this->assertSame($version->id, $document->currentVersion->id);
        $this->assertSame($version->id, $document->versions()->sole()->id);
        $this->assertSame($document->id, $version->document->id);
        $this->assertSame($document->id, $version->currentForDocuments()->sole()->id);
        $this->assertSame($version->id, $user->createdDocumentVersions()->sole()->id);
        $this->assertSame($document->id, $project->documents()->sole()->id);
        $this->assertSame($document->id, $category->documents()->sole()->id);
        $this->assertSame($category->id, $project->categories()->sole()->id);
    }

    public function test_document_tags_use_a_composite_pivot_and_change_request_decisions(): void
    {
        [$user, $project, , $document] = $this->createDocumentContext();
        $tag = Tag::create([
            'project_id' => $project->id,
            'name' => 'Architecture',
            'slug' => 'architecture',
        ]);
        $document->tags()->attach($tag->id);

        $changeRequest = DocumentChangeRequest::create([
            'document_id' => $document->id,
            'proposed_version_number' => 2,
            'proposed_title' => 'Architecture v2',
            'proposed_content' => '# Updated architecture',
            'reason' => 'Clarify deployment flow',
            'status' => 'pending',
            'author_user_id' => $user->id,
        ]);
        $decision = DocumentChangeRequestDecision::create([
            'change_request_id' => $changeRequest->id,
            'decided_by_user_id' => $user->id,
            'decision' => 'approved',
            'comments' => 'Approved',
        ]);

        $this->assertSame($tag->id, $document->tags()->sole()->id);
        $this->assertInstanceOf(DocumentTag::class, $document->tags()->sole()->pivot);
        $this->assertSame($document->id, $tag->documents()->sole()->id);
        $this->assertSame($tag->id, $project->tags()->sole()->id);
        $this->assertSame($changeRequest->id, $document->changeRequests()->sole()->id);
        $this->assertSame($decision->id, $changeRequest->decisions()->sole()->id);
        $this->assertSame($document->id, $changeRequest->document->id);
        $this->assertSame($user->id, $changeRequest->authorUser->id);
        $this->assertSame($user->id, $decision->decidedByUser->id);
        $this->assertSame($decision->id, $user->documentChangeRequestDecisions()->sole()->id);
        $this->assertSame($changeRequest->id, $user->authoredDocumentChangeRequests()->sole()->id);

        $this->assertFalse((new DocumentTag())->getIncrementing());
        $primaryIndex = collect(Schema::getIndexes('document_tags'))
            ->firstWhere('primary', true);
        $this->assertSame(['document_id', 'tag_id'], $primaryIndex['columns']);
    }

    public function test_current_version_composite_foreign_key_rejects_another_documents_version(): void
    {
        [$user, , , $document] = $this->createDocumentContext();
        $otherDocument = Document::create([
            'project_id' => $document->project_id,
            'title' => 'Other document',
            'slug' => 'other-document',
        ]);
        $foreignVersion = DocumentVersion::create([
            'document_id' => $otherDocument->id,
            'version_number' => 1,
            'title' => 'Other document',
            'content_markdown' => '# Other document',
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $document->update(['current_version_id' => $foreignVersion->id]);
    }

    private function createDocumentContext(): array
    {
        $user = User::create([
            'name' => 'Document Author',
            'email' => 'author@example.test',
            'password_hash' => 'test-password',
            'status' => 'active',
        ]);
        $project = Project::create([
            'name' => 'Documentation Project',
            'slug' => 'documentation-project',
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);
        $category = Category::create([
            'project_id' => $project->id,
            'name' => 'Engineering',
            'slug' => 'engineering',
        ]);
        $document = Document::create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'title' => 'Architecture',
            'slug' => 'architecture',
        ]);

        return [$user, $project, $category, $document];
    }
}

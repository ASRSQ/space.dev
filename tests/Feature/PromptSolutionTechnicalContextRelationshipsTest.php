<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Problem;
use App\Models\Project;
use App\Models\PromptChangeRequest;
use App\Models\PromptChangeRequestDecision;
use App\Models\PromptFavorite;
use App\Models\PromptTemplate;
use App\Models\PromptVariable;
use App\Models\PromptVersion;
use App\Models\Solution;
use App\Models\SolutionChangeRequest;
use App\Models\SolutionChangeRequestDecision;
use App\Models\SolutionValidation;
use App\Models\SolutionVersion;
use App\Models\TechnicalContext;
use App\Models\TechnicalContextChangeRequest;
use App\Models\TechnicalContextChangeRequestDecision;
use App\Models\TechnicalContextVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromptSolutionTechnicalContextRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
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

        Schema::create('problems', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity');
            $table->string('status');
            $table->unsignedBigInteger('reporter_user_id');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->foreign(['current_version_id', 'id'])
                ->references(['id', 'prompt_template_id'])
                ->on('prompt_versions');
        });

        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_template_id');
            $table->unsignedInteger('version_number');
            $table->text('system_prompt')->nullable();
            $table->mediumText('user_prompt_template');
            $table->json('model_parameters_json')->nullable();
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('prompt_template_id')->references('id')->on('prompt_templates');
            $table->unique(['prompt_template_id', 'version_number']);
            $table->unique(['id', 'prompt_template_id']);
        });

        Schema::create('prompt_variables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_template_id');
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->string('variable_name');
            $table->text('default_value')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('prompt_template_id')->references('id')->on('prompt_templates');
            $table->foreign('prompt_version_id')->references('id')->on('prompt_versions');
            $table->unique(['prompt_template_id', 'prompt_version_id', 'variable_name']);
        });

        Schema::create('prompt_favorites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('prompt_template_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'prompt_template_id']);
        });

        Schema::create('prompt_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_template_id');
            $table->unsignedInteger('proposed_version_number');
            $table->text('proposed_system_prompt')->nullable();
            $table->mediumText('proposed_user_prompt_template');
            $table->json('proposed_model_parameters_json')->nullable();
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
        });

        Schema::create('prompt_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->string('decision');
            $table->text('comments');
            $table->timestamp('decided_at')->useCurrent();
        });

        Schema::create('solutions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('problem_id');
            $table->string('title');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('author_user_id');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['current_version_id', 'id'])
                ->references(['id', 'solution_id'])
                ->on('solution_versions');
        });

        Schema::create('solution_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('solution_id');
            $table->unsignedInteger('version_number');
            $table->mediumText('solution_steps_markdown');
            $table->mediumText('code_snippet')->nullable();
            $table->text('rollback_plan')->nullable();
            $table->text('changelog')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('solution_id')->references('id')->on('solutions');
            $table->unique(['solution_id', 'version_number']);
            $table->unique(['id', 'solution_id']);
        });

        Schema::create('solution_validations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('solution_id');
            $table->unsignedBigInteger('solution_version_id')->nullable();
            $table->unsignedBigInteger('validated_by_user_id');
            $table->string('outcome');
            $table->mediumText('test_log');
            $table->timestamp('validated_at')->useCurrent();
        });

        Schema::create('solution_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('solution_id');
            $table->unsignedInteger('proposed_version_number');
            $table->mediumText('proposed_steps_markdown');
            $table->mediumText('proposed_code_snippet')->nullable();
            $table->text('proposed_rollback_plan')->nullable();
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
        });

        Schema::create('solution_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->string('decision');
            $table->text('comments');
            $table->timestamp('decided_at')->useCurrent();
        });

        Schema::create('technical_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['current_version_id', 'id'])
                ->references(['id', 'technical_context_id'])
                ->on('technical_context_versions');
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
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('technical_context_id')->references('id')->on('technical_contexts');
            $table->unique(['technical_context_id', 'version_number']);
            $table->unique(['id', 'technical_context_id']);
        });

        Schema::create('technical_context_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('technical_context_id');
            $table->unsignedInteger('proposed_version_number');
            $table->mediumText('proposed_architecture_summary');
            $table->json('proposed_tech_stack_json')->nullable();
            $table->mediumText('proposed_conventions_markdown')->nullable();
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('author_user_id');
            $table->timestamps();
        });

        Schema::create('technical_context_change_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedBigInteger('decided_by_user_id');
            $table->string('decision');
            $table->text('comments');
            $table->timestamp('decided_at')->useCurrent();
        });
    }

    public function test_prompt_versions_variables_favorites_and_change_decisions(): void
    {
        [$user, $project, $category] = $this->createBaseRecords();
        $template = PromptTemplate::create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'name' => 'Release summary',
            'slug' => 'release-summary',
            'created_by_user_id' => $user->id,
        ]);
        $version = PromptVersion::create([
            'prompt_template_id' => $template->id,
            'version_number' => 1,
            'system_prompt' => 'Summarize releases.',
            'user_prompt_template' => 'Summarize {{release}}',
            'model_parameters_json' => ['temperature' => 0.2],
            'created_by_user_id' => $user->id,
        ]);
        $template->update(['current_version_id' => $version->id]);
        $variable = PromptVariable::create([
            'prompt_template_id' => $template->id,
            'prompt_version_id' => $version->id,
            'variable_name' => 'release',
            'is_required' => true,
        ]);
        $favorite = PromptFavorite::create([
            'user_id' => $user->id,
            'prompt_template_id' => $template->id,
        ]);
        $changeRequest = PromptChangeRequest::create([
            'prompt_template_id' => $template->id,
            'proposed_version_number' => 2,
            'proposed_user_prompt_template' => 'Summarize {{release}} and risks.',
            'proposed_model_parameters_json' => ['temperature' => 0.1],
            'reason' => 'Add risk summary',
            'status' => 'pending',
            'author_user_id' => $user->id,
        ]);
        $decision = PromptChangeRequestDecision::create([
            'change_request_id' => $changeRequest->id,
            'decided_by_user_id' => $user->id,
            'decision' => 'approved',
            'comments' => 'Approved',
        ]);

        $this->assertSame($version->id, $template->currentVersion->id);
        $this->assertSame($version->id, $template->versions()->sole()->id);
        $this->assertSame($template->id, $version->promptTemplate->id);
        $this->assertSame($template->id, $version->currentForPromptTemplates()->sole()->id);
        $this->assertSame($variable->id, $template->variables()->sole()->id);
        $this->assertSame($variable->id, $version->variables()->sole()->id);
        $this->assertSame($template->id, $variable->promptTemplate->id);
        $this->assertSame($version->id, $variable->promptVersion->id);
        $this->assertSame($favorite->id, $template->favorites()->sole()->id);
        $this->assertSame($favorite->id, $user->promptFavorites()->sole()->id);
        $this->assertSame($changeRequest->id, $template->changeRequests()->sole()->id);
        $this->assertSame($decision->id, $changeRequest->decisions()->sole()->id);
        $this->assertSame($template->id, $changeRequest->promptTemplate->id);
        $this->assertSame($user->id, $changeRequest->authorUser->id);
        $this->assertSame($user->id, $decision->decidedByUser->id);
        $this->assertSame($decision->id, $user->promptChangeRequestDecisions()->sole()->id);
        $this->assertSame($changeRequest->id, $user->authoredPromptChangeRequests()->sole()->id);
        $this->assertSame($version->id, $user->createdPromptVersions()->sole()->id);
        $this->assertSame($template->id, $project->promptTemplates()->sole()->id);
        $this->assertSame($template->id, $category->promptTemplates()->sole()->id);
        $this->assertSame($template->id, $user->createdPromptTemplates()->sole()->id);
        $this->assertSame(['temperature' => 0.2], $version->model_parameters_json);
        $this->assertIsInt($version->version_number);
    }

    public function test_prompt_current_version_composite_foreign_key_rejects_another_templates_version(): void
    {
        [$user, $project] = $this->createBaseRecords();
        $templateA = $this->createPromptTemplate($user, $project, 'prompt-a');
        $templateB = $this->createPromptTemplate($user, $project, 'prompt-b');
        $foreignVersion = PromptVersion::create([
            'prompt_template_id' => $templateB->id,
            'version_number' => 1,
            'user_prompt_template' => 'Prompt B',
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        $templateA->update(['current_version_id' => $foreignVersion->id]);
    }

    public function test_solution_versions_validations_and_change_decisions(): void
    {
        [$user, $project] = $this->createBaseRecords();
        $problem = Problem::create([
            'project_id' => $project->id,
            'title' => 'Deployment issue',
            'severity' => 'high',
            'status' => 'open',
            'reporter_user_id' => $user->id,
        ]);
        $solution = Solution::create([
            'problem_id' => $problem->id,
            'title' => 'Restart worker',
            'status' => 'draft',
            'author_user_id' => $user->id,
        ]);
        $version = SolutionVersion::create([
            'solution_id' => $solution->id,
            'version_number' => 1,
            'solution_steps_markdown' => 'Restart the worker process.',
            'created_by_user_id' => $user->id,
        ]);
        $solution->update(['current_version_id' => $version->id]);
        $validation = SolutionValidation::create([
            'solution_id' => $solution->id,
            'solution_version_id' => $version->id,
            'validated_by_user_id' => $user->id,
            'outcome' => 'passed',
            'test_log' => 'Worker restarted successfully.',
        ]);
        $changeRequest = SolutionChangeRequest::create([
            'solution_id' => $solution->id,
            'proposed_version_number' => 2,
            'proposed_steps_markdown' => 'Add a health check.',
            'reason' => 'Improve verification',
            'status' => 'pending',
            'author_user_id' => $user->id,
        ]);
        $decision = SolutionChangeRequestDecision::create([
            'change_request_id' => $changeRequest->id,
            'decided_by_user_id' => $user->id,
            'decision' => 'approved',
            'comments' => 'Approved',
        ]);

        $this->assertSame($solution->id, $problem->solutions()->sole()->id);
        $this->assertSame($solution->id, $user->authoredSolutions()->sole()->id);
        $this->assertSame($version->id, $solution->currentVersion->id);
        $this->assertSame($version->id, $solution->versions()->sole()->id);
        $this->assertSame($solution->id, $version->solution->id);
        $this->assertSame($solution->id, $version->currentForSolutions()->sole()->id);
        $this->assertSame($validation->id, $solution->validations()->sole()->id);
        $this->assertSame($validation->id, $version->validations()->sole()->id);
        $this->assertSame($validation->id, $user->solutionValidations()->sole()->id);
        $this->assertSame($version->id, $validation->solutionVersion->id);
        $this->assertSame($solution->id, $validation->solution->id);
        $this->assertSame($changeRequest->id, $solution->changeRequests()->sole()->id);
        $this->assertSame($decision->id, $changeRequest->decisions()->sole()->id);
        $this->assertSame($solution->id, $changeRequest->solution->id);
        $this->assertSame($user->id, $changeRequest->authorUser->id);
        $this->assertSame($user->id, $decision->decidedByUser->id);
        $this->assertSame($decision->id, $user->solutionChangeRequestDecisions()->sole()->id);
        $this->assertSame($changeRequest->id, $user->authoredSolutionChangeRequests()->sole()->id);
        $this->assertSame($version->id, $user->createdSolutionVersions()->sole()->id);
        $this->assertIsInt($version->version_number);
    }

    public function test_solution_current_version_composite_foreign_key_rejects_another_solutions_version(): void
    {
        [$user, $project] = $this->createBaseRecords();
        $problem = Problem::create([
            'project_id' => $project->id,
            'title' => 'Problem',
            'severity' => 'low',
            'status' => 'open',
            'reporter_user_id' => $user->id,
        ]);
        $solutionA = $this->createSolution($user, $problem, 'Solution A');
        $solutionB = $this->createSolution($user, $problem, 'Solution B');
        $foreignVersion = SolutionVersion::create([
            'solution_id' => $solutionB->id,
            'version_number' => 1,
            'solution_steps_markdown' => 'Version of solution B',
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        $solutionA->update(['current_version_id' => $foreignVersion->id]);
    }

    public function test_technical_context_versions_and_change_decisions(): void
    {
        [$user, $project] = $this->createBaseRecords();
        $context = TechnicalContext::create([
            'project_id' => $project->id,
            'title' => 'Runtime architecture',
            'slug' => 'runtime-architecture',
            'description' => 'Runtime components and conventions.',
        ]);
        $version = TechnicalContextVersion::create([
            'technical_context_id' => $context->id,
            'version_number' => 1,
            'architecture_summary' => 'A web app and worker.',
            'tech_stack_json' => ['framework' => 'Laravel'],
            'created_by_user_id' => $user->id,
        ]);
        $context->update(['current_version_id' => $version->id]);
        $changeRequest = TechnicalContextChangeRequest::create([
            'technical_context_id' => $context->id,
            'proposed_version_number' => 2,
            'proposed_architecture_summary' => 'Add a queue worker.',
            'proposed_tech_stack_json' => ['queue' => 'database'],
            'reason' => 'Document async processing',
            'status' => 'pending',
            'author_user_id' => $user->id,
        ]);
        $decision = TechnicalContextChangeRequestDecision::create([
            'change_request_id' => $changeRequest->id,
            'decided_by_user_id' => $user->id,
            'decision' => 'approved',
            'comments' => 'Approved',
        ]);

        $this->assertSame($context->id, $project->technicalContexts()->sole()->id);
        $this->assertSame($version->id, $context->currentVersion->id);
        $this->assertSame($version->id, $context->versions()->sole()->id);
        $this->assertSame($context->id, $version->technicalContext->id);
        $this->assertSame($context->id, $version->currentForTechnicalContexts()->sole()->id);
        $this->assertSame($changeRequest->id, $context->changeRequests()->sole()->id);
        $this->assertSame($decision->id, $changeRequest->decisions()->sole()->id);
        $this->assertSame($context->id, $changeRequest->technicalContext->id);
        $this->assertSame($user->id, $changeRequest->authorUser->id);
        $this->assertSame($user->id, $decision->decidedByUser->id);
        $this->assertSame($version->id, $user->createdTechnicalContextVersions()->sole()->id);
        $this->assertSame($changeRequest->id, $user->authoredTechnicalContextChangeRequests()->sole()->id);
        $this->assertSame($decision->id, $user->technicalContextChangeRequestDecisions()->sole()->id);
        $this->assertSame(['framework' => 'Laravel'], $version->tech_stack_json);
        $this->assertSame(['queue' => 'database'], $changeRequest->proposed_tech_stack_json);
        $this->assertIsInt($version->version_number);
    }

    public function test_technical_context_current_version_composite_foreign_key_rejects_another_contexts_version(): void
    {
        [$user, $project] = $this->createBaseRecords();
        $contextA = $this->createTechnicalContext($project, 'context-a');
        $contextB = $this->createTechnicalContext($project, 'context-b');
        $foreignVersion = TechnicalContextVersion::create([
            'technical_context_id' => $contextB->id,
            'version_number' => 1,
            'architecture_summary' => 'Context B only.',
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        $contextA->update(['current_version_id' => $foreignVersion->id]);
    }

    private function createBaseRecords(): array
    {
        $user = User::create([
            'name' => 'Domain Author',
            'email' => 'domain-author@example.test',
            'password_hash' => 'test-password',
            'status' => 'active',
        ]);
        $project = Project::create([
            'name' => 'Domain Project',
            'slug' => 'domain-project',
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);
        $category = Category::create([
            'project_id' => $project->id,
            'name' => 'Prompts',
            'slug' => 'prompts',
        ]);

        return [$user, $project, $category];
    }

    private function createPromptTemplate(User $user, Project $project, string $slug): PromptTemplate
    {
        return PromptTemplate::create([
            'project_id' => $project->id,
            'name' => $slug,
            'slug' => $slug,
            'created_by_user_id' => $user->id,
        ]);
    }

    private function createSolution(User $user, Problem $problem, string $title): Solution
    {
        return Solution::create([
            'problem_id' => $problem->id,
            'title' => $title,
            'status' => 'draft',
            'author_user_id' => $user->id,
        ]);
    }

    private function createTechnicalContext(Project $project, string $slug): TechnicalContext
    {
        return TechnicalContext::create([
            'project_id' => $project->id,
            'title' => $slug,
            'slug' => $slug,
        ]);
    }
}

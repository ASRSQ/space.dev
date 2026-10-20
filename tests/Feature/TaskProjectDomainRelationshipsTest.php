<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectMembership;
use App\Models\ProjectTechnology;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskRelation;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Tests\TestCase;

class TaskProjectDomainRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type');
            $table->string('priority');
            $table->string('status');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('target_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('relation_type');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_task_id', 'target_task_id', 'relation_type']);
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('comment_text');
            $table->timestamps();
        });

        Schema::create('task_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('old_status');
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('project_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role_in_project');
            $table->string('status');
            $table->timestamp('granted_at')->useCurrent();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('project_environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('env_type');
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('project_technologies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('version_specified')->nullable();
            $table->string('category');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'name']);
        });
    }

    public function test_project_and_user_inverse_relations_follow_the_foreign_keys(): void
    {
        [$owner, $worker, $project, $task] = $this->createProjectTaskAndUsers();

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $worker->id,
            'comment_text' => 'Progress update',
        ]);
        $history = TaskStatusHistory::create([
            'task_id' => $task->id,
            'changed_by_user_id' => $worker->id,
            'old_status' => 'todo',
            'new_status' => 'in_progress',
        ]);
        $membership = ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $worker->id,
            'role_in_project' => 'contributor',
            'status' => 'revoked',
            'granted_by_user_id' => $owner->id,
            'revoked_at' => now(),
            'revoked_by_user_id' => $owner->id,
        ]);
        $environment = ProjectEnvironment::create([
            'project_id' => $project->id,
            'name' => 'Testing',
            'env_type' => 'testing',
            'is_active' => true,
        ]);
        $technology = ProjectTechnology::create([
            'project_id' => $project->id,
            'name' => 'Laravel',
            'category' => 'framework',
        ]);

        $this->assertSame($task->id, $project->tasks()->sole()->id);
        $this->assertSame($membership->id, $project->memberships()->sole()->id);
        $this->assertSame($environment->id, $project->environments()->sole()->id);
        $this->assertSame($technology->id, $project->technologies()->sole()->id);
        $this->assertSame($project->id, $owner->ownedProjects()->sole()->id);
        $this->assertSame($task->id, $worker->assignedTasks()->sole()->id);
        $this->assertSame($task->id, $owner->reportedTasks()->sole()->id);
        $this->assertSame($comment->id, $worker->taskComments()->sole()->id);
        $this->assertSame($history->id, $worker->taskStatusChanges()->sole()->id);
        $this->assertSame($membership->id, $worker->projectMemberships()->sole()->id);
        $this->assertSame($membership->id, $owner->grantedProjectMemberships()->sole()->id);
        $this->assertSame($membership->id, $owner->revokedProjectMemberships()->sole()->id);
        $this->assertSame($worker->id, $task->assignedUser->id);
        $this->assertSame($owner->id, $task->reporter->id);
        $this->assertSame($task->id, $comment->task->id);
        $this->assertSame($worker->id, $comment->user->id);
        $this->assertSame($task->id, $history->task->id);
        $this->assertSame($worker->id, $history->changedByUser->id);
        $this->assertSame($project->id, $membership->project->id);
        $this->assertSame($worker->id, $membership->user->id);
        $this->assertSame($owner->id, $membership->grantedByUser->id);
        $this->assertSame($owner->id, $membership->revokedByUser->id);
        $this->assertSame($project->id, $environment->project->id);
        $this->assertSame($project->id, $technology->project->id);
    }

    public function test_task_relation_uses_both_task_foreign_keys_and_rejects_self_relations(): void
    {
        [, , , $source] = $this->createProjectTaskAndUsers();
        $target = $this->createTask([
            'project_id' => $source->project_id,
            'title' => 'Target task',
            'task_type' => 'bug',
            'priority' => 'low',
            'status' => 'todo',
            'reporter_user_id' => $source->reporter_user_id,
        ]);
        $relation = TaskRelation::create([
            'source_task_id' => $source->id,
            'target_task_id' => $target->id,
            'relation_type' => 'blocks',
        ]);

        $this->assertSame($relation->id, $source->outgoingRelations()->sole()->id);
        $this->assertSame($relation->id, $target->incomingRelations()->sole()->id);
        $this->assertSame($source->id, $relation->sourceTask->id);
        $this->assertSame($target->id, $relation->targetTask->id);
        $this->assertSame('source_task_id', $relation->sourceTask()->getForeignKeyName());
        $this->assertSame('target_task_id', $relation->targetTask()->getForeignKeyName());

        try {
            TaskRelation::create([
                'source_task_id' => $source->id,
                'target_task_id' => $source->id,
                'relation_type' => 'relates_to',
            ]);
            $this->fail('Self-relations must be rejected before persistence.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A task cannot be related to itself.', $exception->getMessage());
        }
    }

    public function test_duplicate_task_relation_triplets_are_rejected_by_the_composite_unique_key(): void
    {
        [, , , $source] = $this->createProjectTaskAndUsers();
        $target = $this->createTask([
            'project_id' => $source->project_id,
            'title' => 'Target task',
            'task_type' => 'bug',
            'priority' => 'low',
            'status' => 'todo',
            'reporter_user_id' => $source->reporter_user_id,
        ]);

        TaskRelation::create([
            'source_task_id' => $source->id,
            'target_task_id' => $target->id,
            'relation_type' => 'blocks',
        ]);

        $this->expectException(QueryException::class);
        TaskRelation::create([
            'source_task_id' => $source->id,
            'target_task_id' => $target->id,
            'relation_type' => 'blocks',
        ]);
    }

    public function test_virtual_active_membership_key_is_not_mass_assignable(): void
    {
        $this->assertNotContains(
            'active_user_project_key',
            (new ProjectMembership())->getFillable()
        );
    }

    private function createProjectTaskAndUsers(): array
    {
        $owner = $this->createUser('owner@example.test');
        $worker = $this->createUser('worker@example.test');
        $project = Project::create([
            'name' => 'Test Project',
            'slug' => 'test-project',
            'status' => 'active',
            'owner_user_id' => $owner->id,
        ]);
        $task = $this->createTask([
            'project_id' => $project->id,
            'title' => 'Source task',
            'task_type' => 'feature',
            'priority' => 'medium',
            'status' => 'todo',
            'assigned_user_id' => $worker->id,
            'reporter_user_id' => $owner->id,
        ]);

        return [$owner, $worker, $project, $task];
    }

    private function createTask(array $attributes): Task
    {
        $task = new Task(Arr::except($attributes, [
            'status',
            'reporter_user_id',
            'is_archived',
            'archived_at',
        ]));

        foreach (['status', 'reporter_user_id', 'is_archived', 'archived_at'] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $task->{$attribute} = $attributes[$attribute];
            }
        }

        $task->saveOrFail();

        return $task;
    }

    private function createUser(string $email): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password_hash' => 'test-password',
            'status' => 'active',
        ]);
    }
}

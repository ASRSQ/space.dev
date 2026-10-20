<?php

namespace Tests\Feature;

use App\Models\AiProviderConfig;
use App\Models\AssistantAction;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\AuditLog;
use App\Models\GithubConnection;
use App\Models\GithubQueryLog;
use App\Models\GithubWebhookEvent;
use App\Models\Problem;
use App\Models\ProblemDiagnosis;
use App\Models\ProblemEventHistory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;
use LogicException;
use Tests\TestCase;

class IntegrationAndAuditModelsTest extends TestCase
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
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status');
            $table->foreignId('owner_user_id')->constrained('users');
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('github_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('repository_owner');
            $table->string('repository_name');
            $table->unsignedBigInteger('installation_id');
            $table->text('access_token_encrypted');
            $table->string('token_key_id');
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('github_query_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('http_method')->default('GET');
            $table->string('request_summary');
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('response_time_ms');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('github_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event_type');
            $table->string('repository_full_name');
            $table->char('payload_hash', 64);
            $table->string('action')->nullable();
            $table->string('processed_status')->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_name');
            $table->string('model_alias');
            $table->text('api_key_encrypted');
            $table->string('key_vault_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('rate_limit_rpm')->default(60);
            $table->timestamps();
            $table->unique(['provider_name', 'model_alias']);
        });

        Schema::create('assistant_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('sender_type');
            $table->mediumText('message_content');
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('assistant_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->string('action_type');
            $table->string('target_resource_type');
            $table->unsignedBigInteger('target_resource_id');
            $table->string('execution_status')->default('proposed');
            $table->json('parameters_json')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('problems', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity');
            $table->string('status');
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('problem_diagnoses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->mediumText('root_cause');
            $table->text('reproduction_steps')->nullable();
            $table->text('proposed_action')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('problem_event_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function test_github_and_ai_models_match_project_keys_and_hide_encrypted_secrets(): void
    {
        [$user, $project] = $this->createUserAndProject();
        $connection = GithubConnection::create([
            'project_id' => $project->id,
            'repository_owner' => 'space-dev',
            'repository_name' => 'platform',
            'installation_id' => 12345,
            'access_token_encrypted' => 'encrypted-token-value',
            'token_key_id' => 'key-v1',
            'is_active' => true,
        ]);
        $queryLog = GithubQueryLog::create([
            'project_id' => $project->id,
            'endpoint' => '/repos/space-dev/platform',
            'http_method' => 'GET',
            'request_summary' => 'Read repository metadata',
            'http_status' => 200,
            'response_time_ms' => 125,
        ]);
        $provider = AiProviderConfig::create([
            'provider_name' => 'example-provider',
            'model_alias' => 'assistant-default',
            'api_key_encrypted' => 'encrypted-api-key-value',
            'key_vault_reference' => 'vault://assistant/default',
            'is_active' => true,
            'rate_limit_rpm' => 60,
        ]);

        $this->assertSame($connection->id, $project->githubConnection->id);
        $this->assertSame($project->id, $connection->project->id);
        $this->assertSame($queryLog->id, $project->githubQueryLogs()->sole()->id);
        $this->assertSame($project->id, $queryLog->project->id);
        $this->assertIsBool($connection->is_active);
        $this->assertIsInt($queryLog->http_status);
        $this->assertIsInt($queryLog->response_time_ms);
        $this->assertArrayNotHasKey('access_token_encrypted', $connection->toArray());
        $this->assertIsBool($provider->is_active);
        $this->assertIsInt($provider->rate_limit_rpm);
        $this->assertArrayNotHasKey('api_key_encrypted', $provider->toArray());
        $this->assertSame('key-v1', $connection->token_key_id);
    }

    public function test_github_and_ai_secrets_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        [, $project] = $this->createUserAndProject();
        $githubToken = 'fake-github-token-for-test-'.str_repeat('g', 120);
        $apiKey = 'fake-ai-key-for-test-'.str_repeat('a', 120);

        $connection = GithubConnection::create([
            'project_id' => $project->id,
            'repository_owner' => 'space-dev',
            'repository_name' => 'platform',
            'installation_id' => 23456,
            'access_token_encrypted' => $githubToken,
            'token_key_id' => 'test-key-v1',
            'is_active' => true,
        ]);
        $provider = AiProviderConfig::create([
            'provider_name' => 'test-provider',
            'model_alias' => 'test-model',
            'api_key_encrypted' => $apiKey,
            'is_active' => true,
            'rate_limit_rpm' => 30,
        ]);

        $storedGithubToken = DB::table('github_connections')->where('id', $connection->id)->value('access_token_encrypted');
        $storedApiKey = DB::table('ai_provider_configs')->where('id', $provider->id)->value('api_key_encrypted');
        $freshConnection = $connection->fresh();
        $freshProvider = $provider->fresh();

        $this->assertTrue(is_string($storedGithubToken) && $storedGithubToken !== $githubToken);
        $this->assertTrue(is_string($storedApiKey) && $storedApiKey !== $apiKey);
        $this->assertTrue(hash_equals($githubToken, $freshConnection->access_token_encrypted));
        $this->assertTrue(hash_equals($apiKey, $freshProvider->api_key_encrypted));
        $this->assertTrue(strlen($storedGithubToken) < 65536);
        $this->assertTrue(strlen($storedApiKey) < 65536);
        $this->assertArrayNotHasKey('access_token_encrypted', $freshConnection->toArray());
        $this->assertArrayNotHasKey('api_key_encrypted', $freshProvider->toArray());
        $this->assertFalse(str_contains($freshConnection->toJson(), $githubToken));
        $this->assertFalse(str_contains($freshProvider->toJson(), $apiKey));
    }

    public function test_github_connection_rejects_invalid_persisted_ciphertext(): void
    {
        [, $project] = $this->createUserAndProject();
        $connection = GithubConnection::create([
            'project_id' => $project->id,
            'repository_owner' => 'space-dev',
            'repository_name' => 'platform',
            'installation_id' => 34567,
            'access_token_encrypted' => 'fake-token-for-invalid-cipher-test',
            'token_key_id' => 'test-key-v1',
            'is_active' => true,
        ]);
        DB::table('github_connections')->where('id', $connection->id)
            ->update(['access_token_encrypted' => 'not-a-valid-laravel-ciphertext']);

        $this->expectException(DecryptException::class);
        $connection->fresh()->access_token_encrypted;
    }

    public function test_ai_provider_config_rejects_invalid_persisted_ciphertext(): void
    {
        $provider = AiProviderConfig::create([
            'provider_name' => 'test-provider',
            'model_alias' => 'invalid-cipher-test',
            'api_key_encrypted' => 'fake-key-for-invalid-cipher-test',
            'is_active' => true,
            'rate_limit_rpm' => 30,
        ]);
        DB::table('ai_provider_configs')->where('id', $provider->id)
            ->update(['api_key_encrypted' => 'not-a-valid-laravel-ciphertext']);

        $this->expectException(DecryptException::class);
        $provider->fresh()->api_key_encrypted;
    }

    public function test_webhook_delivery_id_uniqueness_is_preserved_without_model_side_effects(): void
    {
        $event = GithubWebhookEvent::create([
            'delivery_id' => 'delivery-123',
            'event_type' => 'push',
            'repository_full_name' => 'space-dev/platform',
            'payload_hash' => str_repeat('a', 64),
            'action' => 'completed',
            'processed_status' => 'received',
        ]);

        $this->assertFalse($event->usesTimestamps());
        $this->assertSame('delivery-123', $event->delivery_id);
        $this->assertNotNull($event->fresh()->created_at);

        $this->expectException(QueryException::class);
        GithubWebhookEvent::create([
            'delivery_id' => 'delivery-123',
            'event_type' => 'push',
            'repository_full_name' => 'space-dev/platform',
            'payload_hash' => str_repeat('b', 64),
            'processed_status' => 'received',
        ]);
    }

    public function test_assistant_conversation_messages_and_actions_follow_foreign_keys(): void
    {
        [$user, $project] = $this->createUserAndProject();
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Deployment review',
        ]);
        $message = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'assistant',
            'message_content' => 'I found one deployment risk.',
            'tokens_used' => 42,
        ]);
        $action = AssistantAction::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'action_type' => 'create_task',
            'target_resource_type' => 'task',
            'target_resource_id' => 99,
            'execution_status' => 'proposed',
            'parameters_json' => ['title' => 'Review deployment'],
            'result_json' => null,
        ]);
        $conversationAction = AssistantAction::create([
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'action_type' => 'inspect_project',
            'target_resource_type' => 'project',
            'target_resource_id' => $project->id,
            'execution_status' => 'proposed',
        ]);

        $this->assertSame($conversation->id, $user->assistantConversations()->sole()->id);
        $this->assertSame($conversation->id, $project->assistantConversations()->sole()->id);
        $this->assertSame($message->id, $conversation->messages()->sole()->id);
        $this->assertCount(2, $conversation->actions);
        $this->assertTrue($conversation->actions->contains('id', $action->id));
        $this->assertTrue($conversation->actions->contains('id', $conversationAction->id));
        $this->assertSame($action->id, $message->actions()->sole()->id);
        $this->assertSame($conversation->id, $message->conversation->id);
        $this->assertSame($conversation->id, $action->conversation->id);
        $this->assertSame($message->id, $action->message->id);
        $this->assertSame(['title' => 'Review deployment'], $action->parameters_json);
        $this->assertNull($action->result_json);
        $this->assertNull($conversationAction->message);
        $this->assertIsInt($message->tokens_used);
    }

    public function test_audit_logs_diagnoses_and_problem_events_have_inverse_relations(): void
    {
        [$user, $project] = $this->createUserAndProject();
        $problem = Problem::create([
            'project_id' => $project->id,
            'title' => 'Queue worker stopped',
            'description' => 'Jobs stopped processing.',
            'severity' => 'high',
            'status' => 'open',
            'reporter_user_id' => $user->id,
        ]);
        $diagnosis = ProblemDiagnosis::create([
            'problem_id' => $problem->id,
            'author_user_id' => $user->id,
            'root_cause' => 'Worker process exited.',
            'reproduction_steps' => 'Stop the worker.',
            'proposed_action' => 'Restart the worker.',
        ]);
        $event = ProblemEventHistory::create([
            'problem_id' => $problem->id,
            'user_id' => $user->id,
            'event_type' => 'diagnosed',
            'notes' => 'Diagnosis recorded.',
        ]);
        $log = AuditLog::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action' => 'problem.updated',
            'resource_type' => 'problem',
            'resource_id' => $problem->id,
            'old_values_json' => ['status' => 'open'],
            'new_values_json' => ['status' => 'diagnosed'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
        ]);

        $this->assertSame($diagnosis->id, $problem->diagnoses()->sole()->id);
        $this->assertSame($event->id, $problem->eventHistories()->sole()->id);
        $this->assertSame($problem->id, $user->reportedProblems()->sole()->id);
        $this->assertSame($problem->id, $diagnosis->problem->id);
        $this->assertSame($user->id, $diagnosis->authorUser->id);
        $this->assertSame($problem->id, $event->problem->id);
        $this->assertSame($user->id, $event->user->id);
        $this->assertSame($diagnosis->id, $user->authoredProblemDiagnoses()->sole()->id);
        $this->assertSame($event->id, $user->problemEventHistories()->sole()->id);
        $this->assertSame($log->id, $user->auditLogs()->sole()->id);
        $this->assertSame($log->id, $project->auditLogs()->sole()->id);
        $this->assertSame($user->id, $log->user->id);
        $this->assertSame($project->id, $log->project->id);
        $this->assertSame(['status' => 'open'], $log->old_values_json);
        $this->assertSame(['status' => 'diagnosed'], $log->new_values_json);
        $this->assertFalse($log->usesTimestamps());
    }

    public function test_audit_log_supports_schema_nullable_user_and_project_foreign_keys(): void
    {
        $log = AuditLog::create([
            'action' => 'system.maintenance',
            'resource_type' => 'application',
            'resource_id' => 1,
            'old_values_json' => null,
            'new_values_json' => ['maintenance' => 'completed'],
        ]);

        $this->assertNull($log->user);
        $this->assertNull($log->project);
        $this->assertNull($log->old_values_json);
        $this->assertSame(['maintenance' => 'completed'], $log->new_values_json);
    }

    public function test_audit_log_creation_is_allowed_but_instance_updates_are_blocked(): void
    {
        $log = $this->createAuditLog();

        try {
            $log->update(['action' => 'audit.changed']);
            $this->fail('Updating an audit log should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit log records cannot be updated.', $exception->getMessage());
        }

        $this->assertSame('audit.created', $log->fresh()->action);
    }

    public function test_audit_log_instance_deletions_are_blocked(): void
    {
        $log = $this->createAuditLog();

        try {
            $log->delete();
            $this->fail('Deleting an audit log should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit log records cannot be deleted.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_audit_log_query_updates_and_deletions_are_blocked(): void
    {
        $log = $this->createAuditLog();

        try {
            AuditLog::query()->whereKey($log->id)->update(['action' => 'audit.changed']);
            $this->fail('Bulk updating audit logs should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit log records cannot be updated.', $exception->getMessage());
        }

        try {
            AuditLog::query()->whereKey($log->id)->delete();
            $this->fail('Bulk deleting audit logs should throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit log records cannot be deleted.', $exception->getMessage());
        }

        $this->assertSame('audit.created', $log->fresh()->action);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    private function createAuditLog(): AuditLog
    {
        return AuditLog::create([
            'action' => 'audit.created',
            'resource_type' => 'test',
            'resource_id' => 1,
        ]);
    }

    private function createUserAndProject(): array
    {
        $user = User::create([
            'name' => 'Integration User',
            'email' => 'integration@example.test',
            'password_hash' => 'test-password',
            'status' => 'active',
        ]);
        $project = Project::create([
            'name' => 'Integration Project',
            'slug' => 'integration-project',
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        return [$user, $project];
    }
}

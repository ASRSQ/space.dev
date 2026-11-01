<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PromptTemplate;
use App\Models\Task;
use App\Models\Team;
use App\Models\TechnicalContext;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProblemPolicy;
use App\Policies\ProjectDocumentationPolicy;
use App\Policies\ProjectMembershipPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\PromptTemplatePolicy;
use App\Policies\TaskPolicy;
use App\Policies\TeamPolicy;
use App\Policies\TechnicalContextPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('active-eloquent', function ($app, array $config): ActiveUserProvider {
            return new ActiveUserProvider($app['hash'], $config['model']);
        });

        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Problem::class, ProblemPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(ProjectMembership::class, ProjectMembershipPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(TechnicalContext::class, TechnicalContextPolicy::class);
        Gate::policy(PromptTemplate::class, PromptTemplatePolicy::class);
        Gate::define('view-project-documentation', [ProjectDocumentationPolicy::class, 'view']);
        Gate::define('edit-project-documentation', [ProjectDocumentationPolicy::class, 'edit']);
        Gate::define('submit-project-documentation', [ProjectDocumentationPolicy::class, 'submit']);
        Gate::define('review-project-documentation', [ProjectDocumentationPolicy::class, 'review']);
    }
}

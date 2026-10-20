<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Task;
use App\Policies\TaskPolicy;
use App\Policies\ProjectDocumentationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
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
        Gate::define('view-project-documentation', [ProjectDocumentationPolicy::class, 'view']);
        Gate::define('edit-project-documentation', [ProjectDocumentationPolicy::class, 'edit']);
        Gate::define('submit-project-documentation', [ProjectDocumentationPolicy::class, 'submit']);
        Gate::define('review-project-documentation', [ProjectDocumentationPolicy::class, 'review']);
    }
}

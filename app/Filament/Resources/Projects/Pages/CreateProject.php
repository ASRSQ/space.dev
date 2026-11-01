<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Gate::authorize('create', Project::class);
        if (filled($data['organization_id'] ?? null)) {
            abort_unless(Organization::query()->whereKey($data['organization_id'])->whereNull('archived_at')->exists(), 422);
            abort_unless(app(ProjectAccess::class)->organizationMember(User::findOrFail($data['owner_user_id']), (int) $data['organization_id']), 422);
        }

        return Project::query()->create(Arr::only($data, ['name', 'slug', 'description', 'repository_url', 'owner_user_id', 'organization_id']));
    }
}

<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\ProjectAccess;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $project = Project::query()->lockForUpdate()->findOrFail($record->id);
            Gate::authorize('update', $project);
            $fields = ['name', 'slug', 'description', 'repository_url'];
            if (app(ProjectAccess::class)->platform(auth()->user())) {
                $fields[] = 'owner_user_id';
            }
            $project->update(Arr::only($data, $fields));

            return $project;
        });
    }
}

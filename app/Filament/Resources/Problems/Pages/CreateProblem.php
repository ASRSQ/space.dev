<?php

namespace App\Filament\Resources\Problems\Pages;

use App\Filament\Resources\Problems\ProblemResource;
use App\Models\Problem;
use App\Models\Project;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateProblem extends CreateRecord
{
    protected static string $resource = ProblemResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Problem {
            Gate::authorize('create', Problem::class);
            $project = Project::query()->lockForUpdate()->findOrFail($data['project_id']);
            if ($project->status !== 'active' || $project->isArchived()) {
                throw ValidationException::withMessages(['data.project_id' => 'Novos erros exigem um projeto ativo.']);
            }

            return Problem::query()->create(Arr::only($data, ['project_id', 'title', 'description', 'severity', 'status']) + ['reporter_user_id' => auth()->id()]);
        });
    }
}

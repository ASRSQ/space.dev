<?php

namespace App\Filament\Resources\Problems\Pages;

use App\Filament\Concerns\ProtectsEditorFromStaleUpdates;
use App\Filament\Resources\Problems\ProblemResource;
use App\Models\Problem;
use App\Models\Project;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EditProblem extends EditRecord
{
    use ProtectsEditorFromStaleUpdates;

    protected static string $resource = ProblemResource::class;

    protected function editableRecordFields(): array
    {
        return ['title', 'description', 'severity', 'status'];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Problem {
            $problem = Problem::query()->lockForUpdate()->findOrFail($record->getKey());
            Project::query()->lockForUpdate()->findOrFail($problem->project_id);
            Gate::authorize('update', $problem);
            $this->assertEditorRevision($problem);
            $problem->update(Arr::only($data, ['title', 'description', 'severity', 'status']));
            $this->captureEditorRevision($problem->refresh());

            return $problem;
        });
    }
}

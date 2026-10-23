<?php

namespace App\Filament\Concerns;

use App\Models\Project;
use App\Support\TaskMarkdown;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

trait InteractsWithDescriptionChecklist
{
    public function toggleChecklist(int $line, string $revision, bool $checked): void
    {
        try {
            DB::transaction(function () use ($line, $revision, $checked): void {
                $record = $this->getRecord()->newQuery()->lockForUpdate()->findOrFail($this->getRecord()->getKey());
                // Serialize with project archival as well as changes to the description.
                Project::query()->lockForUpdate()->findOrFail($record->project_id);
                Gate::authorize('update', $record);
                if (! hash_equals(hash('sha256', $record->description ?? ''), $revision)) {
                    throw new DomainException('A descrição foi alterada. Confira a versão atual e tente novamente.');
                }
                $record->update(['description' => app(TaskMarkdown::class)->toggle($record->description ?? '', $line, $checked)]);
            });
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->warning()->send();
        }
        $this->getRecord()->refresh();
    }
}

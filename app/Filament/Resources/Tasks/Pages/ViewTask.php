<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Concerns\InteractsWithDescriptionChecklist;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewTask extends ViewRecord
{
    use InteractsWithDescriptionChecklist;

    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->authorize(fn ($record): bool => Gate::allows('update', $record)),
        ];
    }
}

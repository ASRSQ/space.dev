<?php

namespace App\Filament\Resources\Problems\Pages;

use App\Filament\Concerns\InteractsWithDescriptionChecklist;
use App\Filament\Resources\Problems\ProblemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProblem extends ViewRecord
{
    use InteractsWithDescriptionChecklist;

    protected static string $resource = ProblemResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Editar')];
    }
}

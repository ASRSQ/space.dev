<?php

namespace App\Filament\Resources\Projects\Pages;

use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\Projects\ProjectResource;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('documentation')
                ->label('Central de documentação')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => ProjectResource::getUrl('documentation', ['record' => $this->getRecord()])),
            EditAction::make()->label('Editar'),
        ];
    }
}

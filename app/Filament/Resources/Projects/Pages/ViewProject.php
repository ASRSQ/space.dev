<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\ProjectMemberships\ProjectMembershipResource;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('members')->label('Membros do projeto')
                ->url(fn () => ProjectMembershipResource::getUrl('index', ['tableFilters' => ['project_id' => ['value' => $this->getRecord()->id]]])),
            Action::make('documentation')
                ->label('Central de documentação')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => ProjectResource::getUrl('documentation', ['record' => $this->getRecord()])),
            EditAction::make()->label('Editar'),
        ];
    }
}

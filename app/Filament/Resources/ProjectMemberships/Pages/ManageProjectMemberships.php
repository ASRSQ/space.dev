<?php

namespace App\Filament\Resources\ProjectMemberships\Pages;

use App\Filament\Resources\ProjectMemberships\ProjectMembershipResource;
use App\Services\ProjectMembershipService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProjectMemberships extends ManageRecords
{
    protected static string $resource = ProjectMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Adicionar participante')->using(fn (array $data) => app(ProjectMembershipService::class)->grant((int) $data['project_id'], (int) $data['user_id'], $data['role_in_project']))];
    }
}

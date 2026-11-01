<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAdministrationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(UserAdministrationService::class)->save($data, $record);
    }

    protected function afterSave(): void
    {
        $this->data['password'] = null;
        $this->data['password_confirmation'] = null;
    }
}

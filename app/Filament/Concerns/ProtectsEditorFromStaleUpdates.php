<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ProtectsEditorFromStaleUpdates
{
    #[Locked]
    public string $descriptionRevision = '';

    #[Locked]
    public string $editorRevision = '';

    abstract protected function editableRecordFields(): array;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->captureEditorRevision($this->getRecord());

        return $data;
    }

    private function recordRevision(Model $record): string
    {
        return hash('sha256', json_encode(Arr::only($record->getRawOriginal(), $this->editableRecordFields()), JSON_THROW_ON_ERROR));
    }

    protected function captureEditorRevision(Model $record): void
    {
        $this->descriptionRevision = hash('sha256', $record->description ?? '');
        $this->editorRevision = $this->recordRevision($record);
    }

    protected function assertEditorRevision(Model $record): void
    {
        if (! hash_equals($this->descriptionRevision, hash('sha256', $record->description ?? ''))) {
            throw ValidationException::withMessages(['data.description' => 'A descrição foi alterada por outro usuário. Reabra o registro antes de salvar.']);
        }
        if (! hash_equals($this->editorRevision, $this->recordRevision($record))) {
            throw ValidationException::withMessages(['data.title' => 'O registro foi alterado por outro usuário. Reabra antes de salvar.']);
        }
    }
}

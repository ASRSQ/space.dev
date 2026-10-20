@php($extras = $this->renderKanbanSlot($this->toolbarExtras()))

<div class="fi-kanban-toolbar">
    @if ($this->isSearchable())
        <div class="fi-kanban-search">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Pesquisar tarefas" aria-label="Pesquisar tarefas" />
            </x-filament::input.wrapper>
        </div>
    @endif

    <label class="text-sm">Projeto
        <select wire:model.live="projectFilter" class="fi-input w-full rounded-lg border-gray-300">
            <option value="">Todos</option>
            @foreach ($this->projectFilterOptions() as [$id, $name])
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">Prioridade
        <select wire:model.live="priorityFilter" class="fi-input w-full rounded-lg border-gray-300">
            <option value="">Todas</option>
            @foreach ($this->priorityFilterOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">Responsável
        <select wire:model.live="assigneeFilter" class="fi-input w-full rounded-lg border-gray-300">
            <option value="">Todos</option>
            @foreach ($this->assigneeFilterOptions() as [$id, $name])
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">Prazo
        <select wire:model.live="deadlineFilter" class="fi-input w-full rounded-lg border-gray-300">
            <option value="">Todos</option>
            @foreach (\App\Filament\Resources\Tasks\TaskResource::deadlineFilterOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Ordenar por entrega
        <select wire:model.live="deadlineSort" class="fi-input w-full rounded-lg border-gray-300">
            <option value="">Padrão</option><option value="asc">Mais próximas primeiro</option><option value="desc">Mais distantes primeiro</option>
        </select>
    </label>
    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('list') }}" class="text-sm">Ver listagem</a>
    {!! $extras !!}
</div>

<header class="fi-kanban-header">
    @if ($column->icon !== null)
        <x-filament::icon :icon="$column->icon" class="fi-kanban-header-icon" />
    @endif

    <h3 class="fi-kanban-title">{{ $column->title }}</h3>

    <x-filament::badge size="sm" :color="$column->colorName() ?? 'gray'">{{ $column->records->count() }}</x-filament::badge>

    {!! $this->renderKanbanSlot($this->columnHeaderExtras($column)) !!}

    @if ($this->isColumnsCollapsible())
        <button
            type="button"
            class="fi-kanban-collapse"
            x-on:click="toggle(@js($column->id), @js($column->collapsed))"
            x-bind:aria-expanded="(! isCollapsed(@js($column->id), @js($column->collapsed))).toString()"
            x-bind:aria-label="isCollapsed(@js($column->id), @js($column->collapsed)) ? @js(__('filament-kanban::filament-kanban.expand_column', ['column' => $column->title])) : @js(__('filament-kanban::filament-kanban.collapse_column', ['column' => $column->title]))"
        >
            <x-filament::icon icon="heroicon-m-chevron-up-down" class="fi-kanban-collapse-icon" />
        </button>
    @endif
</header>

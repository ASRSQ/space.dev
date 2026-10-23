<div>
    <p>Descrição</p>
    <div class="fi-prose" wire:key="task-description-{{ hash('sha256', $getRecord()->description ?? '') }}">
        {!! app(\App\Support\TaskMarkdown::class)->render($getRecord()->description, \Illuminate\Support\Facades\Gate::allows('update', $getRecord())) !!}
    </div>
    <span wire:loading wire:target="toggleChecklist" role="status">Salvando checklist…</span>
</div>

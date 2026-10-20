@props(['status'])
@php
    [$label, $color, $icon] = match ($status) {
        'draft' => ['Rascunho', 'warning', 'heroicon-o-pencil-square'],
        'review' => ['Em revisão', 'info', 'heroicon-o-clock'],
        'published' => ['Publicado', 'success', 'heroicon-o-check-circle'],
        'approved' => ['Aprovada', 'success', 'heroicon-o-check-circle'],
        'rejected' => ['Rejeitada', 'danger', 'heroicon-o-x-circle'],
        'cancelled' => ['Cancelada', 'gray', 'heroicon-o-minus-circle'],
        default => ['Pendente', 'gray', 'heroicon-o-minus-circle'],
    };
@endphp
<x-filament::badge :color="$color" :icon="$icon">{{ $label }}</x-filament::badge>

@props(['sections', 'selected'])
<aside id="docs-section-navigation" class="docs-sidebar" :class="{ 'is-open': sectionsOpen }">
    <nav aria-label="Seções da documentação">
        <p class="docs-eyebrow docs-sidebar-label">Documentação do projeto</p>
        <ol class="docs-nav-list">
            @foreach ($sections as $key => $section)
                <li wire:key="section-{{ $key }}">
                    <button type="button" class="docs-nav-item"
                        @click="navigateTo(@js($key))"
                        aria-current="{{ $selected === $key ? 'page' : 'false' }}"
                        wire:loading.attr="disabled" wire:target="selectSection">
                        <span class="docs-nav-label">{{ $section['label'] }}</span>
                        <x-documentation.status :status="$section['status']" />
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>
</aside>

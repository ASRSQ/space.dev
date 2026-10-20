<x-filament-panels::page>
    @php
        $sections = $this->sectionStates();
        $current = $sections[$this->selectedSection];
        $architecture = $this->selectedSection === 'architecture';
        $publishedCount = collect($sections)->filter(fn (array $section): bool => $section['published'] !== null)->count();
        $keys = array_keys($sections);
        $position = array_search($this->selectedSection, $keys, true);
        $previous = $keys[$position - 1] ?? null;
        $next = $keys[$position + 1] ?? null;
        $tabs = ['read' => 'Leitura', 'draft' => 'Rascunho', 'approvals' => 'Aprovações', 'history' => 'Histórico'];
    @endphp
    <div class="documentation"
        x-data="{
            sectionsOpen: false,
            tab: $wire.entangle('activeTab'),
            get dirty() { return JSON.stringify($wire.draftData) !== JSON.stringify($wire.savedDraftData) },
            get hasUnsavedChanges() { return this.dirty || Boolean(($wire.reviewReason ?? '').trim()) || Boolean(($wire.decisionComments ?? '').trim()) },
            navigateTo(section) {
                if (section === $wire.selectedSection) { this.sectionsOpen = false; return }
                if (this.hasUnsavedChanges && ! window.confirm('Existem alterações não salvas. Deseja descartá-las e mudar de seção?')) return;
                $wire.selectSection(section);
            },
            moveTab(event) {
                const items = Array.from($refs.tabs.querySelectorAll('[role=tab]'));
                let index = items.indexOf(document.activeElement);
                if (index < 0) return;
                if (event.key === 'ArrowRight') index = (index + 1) % items.length;
                else if (event.key === 'ArrowLeft') index = (index - 1 + items.length) % items.length;
                else if (event.key === 'Home') index = 0;
                else if (event.key === 'End') index = items.length - 1;
                else return;
                event.preventDefault(); items[index].focus(); items[index].click();
            }
        }"
        @documentation-section-changed.window="sectionsOpen = false; $nextTick(() => document.getElementById('docs-section-title')?.focus())"
        @beforeunload.window="if (hasUnsavedChanges) { $event.preventDefault(); $event.returnValue = '' }">
        <x-filament::section compact>
            <div class="docs-overview">
                <div>
                    <p class="docs-eyebrow">Conhecimento do projeto</p>
                    <x-filament::link :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $this->getRecord()])" icon="heroicon-o-arrow-left">{{ $this->getRecord()->name }}</x-filament::link>
                </div>
                <div class="docs-progress">
                    <label for="docs-progress" class="docs-progress-label">
                        <span class="docs-progress-title">Publicação</span>
                        <span class="docs-progress-count">{{ $publishedCount }} de 9 seções publicadas</span>
                    </label>
                    <progress id="docs-progress" max="9" value="{{ $publishedCount }}">{{ $publishedCount }} de 9</progress>
                </div>
            </div>
        </x-filament::section>
        <x-filament::button color="gray" icon="heroicon-o-bars-3" class="docs-section-toggle" @click="sectionsOpen = ! sectionsOpen" x-bind:aria-expanded="sectionsOpen" aria-controls="docs-section-navigation">Seções · {{ $current['label'] }}</x-filament::button>
        <div class="docs-layout">
            <x-documentation.navigation :sections="$sections" :selected="$this->selectedSection" />
            <div class="docs-content" role="region" aria-labelledby="docs-section-title">
                <header class="docs-section-header">
                    <div>
                        <p class="docs-eyebrow">Seção {{ $position + 1 }} de 9</p>
                        <h2 id="docs-section-title" class="docs-title" tabindex="-1">{{ $current['label'] }}</h2>
                        <p class="docs-subtitle">
                            @if ($current['published'])
                                Versão publicada {{ $current['published']->version_number }} · {{ $current['published']->created_at?->format('d/m/Y H:i') }}
                            @else
                                Organize o conhecimento e acompanhe as revisões desta seção.
                            @endif
                        </p>
                    </div>
                    <x-documentation.status :status="$current['status']" />
                </header>
                <div class="docs-tabs" x-ref="tabs" @keydown="moveTab($event)">
                    <x-filament::tabs label="Áreas da seção" role="tablist">
                        @foreach ($tabs as $key => $label)
                            <x-filament::tabs.item :alpine-active="'tab === '.\Illuminate\Support\Js::from($key)"
                                role="tab" id="docs-tab-{{ $key }}" aria-controls="docs-panel-{{ $key }}"
                                x-bind:aria-selected="tab === '{{ $key }}'" x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1" @click="tab = '{{ $key }}'">
                                {{ $label }}
                            </x-filament::tabs.item>
                        @endforeach
                    </x-filament::tabs>
                </div>
                <section id="docs-panel-read" class="docs-panel" role="tabpanel" aria-labelledby="docs-tab-read" tabindex="0" x-bind:hidden="tab !== 'read'" @if ($this->activeTab !== 'read') hidden @endif>
                    @if ($current['published'])
                        <x-filament::section heading="Conteúdo publicado" description="Esta é a versão oficial da documentação.">
                            <x-documentation.content :architecture="$architecture" :content="$current['published']->content_markdown ?? ''" :summary="$current['published']->architecture_summary ?? ''" :technologies="$current['published']->tech_stack_json ?? null" :conventions="$current['published']->conventions_markdown ?? null" />
                        </x-filament::section>
                    @else
                        <x-filament::empty-state heading="Documentação em construção" icon="heroicon-o-book-open" description="Esta seção ainda não possui conteúdo oficial publicado.">
                            <x-slot name="footer">
                                @can('edit-project-documentation', $this->getRecord())
                                    <x-filament::button @click="tab = 'draft'" icon="heroicon-o-pencil-square">Abrir rascunho</x-filament::button>
                                @endcan
                            </x-slot>
                        </x-filament::empty-state>
                    @endif
                    @if ($current['published'] && $current['status'] !== 'published')
                        <x-filament::callout color="info" heading="Existe uma alteração em andamento" description="O rascunho e a proposta em revisão não alteram esta versão publicada." />
                    @endif
                </section>
                <section id="docs-panel-draft" class="docs-panel" role="tabpanel" aria-labelledby="docs-tab-draft" tabindex="0" x-bind:hidden="tab !== 'draft'" @if ($this->activeTab !== 'draft') hidden @endif wire:key="draft-panel-{{ $this->selectedSection }}">
                    @if ($current['status'] === 'review')
                        <x-filament::callout color="info" icon="heroicon-o-clock" heading="Alteração em revisão" description="A edição está bloqueada enquanto a solicitação aguarda uma decisão. Consulte a aba Aprovações." />
                    @else
                        @can('edit-project-documentation', $this->getRecord())
                            <x-filament::section heading="Rascunho" description="Salvar aqui não altera o conteúdo publicado. Use Markdown para formatar o texto.">
                                <form wire:submit="saveDraft" class="docs-stack">
                                    {{ $this->draftForm }}
                                    <div class="docs-actions">
                                        <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="saveDraft">Salvar rascunho</x-filament::button>
                                        <x-filament::button color="gray" wire:click="toggleDraftPreview" x-bind:aria-expanded="$wire.previewDraft" aria-controls="docs-draft-preview">{{ $this->previewDraft ? 'Fechar prévia' : 'Visualizar prévia' }}</x-filament::button>
                                        <span class="docs-unsaved" x-show="dirty" x-cloak role="status">Alterações não salvas</span>
                                    </div>
                                </form>
                            </x-filament::section>
                            @if ($this->previewDraft)
                                <x-filament::section id="docs-draft-preview" heading="Prévia do rascunho" description="Conteúdo não publicado. Esta prévia não salva alterações.">
                                    <x-documentation.content :architecture="$architecture" :content="$this->draftData['draftContent'] ?? ''" :summary="$this->draftData['architectureSummary'] ?? ''" :technologies="$this->previewTechnologies()" :conventions="$this->draftData['conventionsMarkdown'] ?? ''" />
                                </x-filament::section>
                            @endif
                        @endcan
                        @if ($current['draft'])
                            @can('submit-project-documentation', $this->getRecord())
                                <x-filament::section heading="Enviar para revisão" description="Envie o rascunho salvo para aprovação. Alterações ainda não salvas não serão incluídas.">
                                    <form wire:submit="submitForReview" class="docs-stack">
                                        {{ $this->reviewForm }}
                                        <div class="docs-actions">
                                            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" x-bind:disabled="dirty" wire:loading.attr="disabled" wire:target="submitForReview">Enviar para revisão</x-filament::button>
                                            <span class="docs-meta" x-show="dirty" x-cloak>Salve as alterações antes de enviar.</span>
                                        </div>
                                    </form>
                                </x-filament::section>
                            @endcan
                        @endif
                    @endif
                </section>
                <section id="docs-panel-approvals" class="docs-panel" role="tabpanel" aria-labelledby="docs-tab-approvals" tabindex="0" x-bind:hidden="tab !== 'approvals'" @if ($this->activeTab !== 'approvals') hidden @endif>
                    @forelse ($current['requests'] as $request)
                        <x-filament::section :heading="'Solicitação #'.$request->getKey()" wire:key="request-{{ $this->selectedSection }}-{{ $request->getKey() }}">
                            <div class="docs-stack">
                                <div class="docs-actions">
                                    <x-documentation.status :status="$request->status === 'pending' ? 'review' : $request->status" />
                                    <time class="docs-meta">{{ $request->created_at?->format('d/m/Y H:i') }}</time>
                                </div>
                                <p class="text-sm"><strong>Motivo:</strong> {{ $request->reason }}</p>
                                <details @if ($request->status === 'pending') open @endif>
                                    <summary class="cursor-pointer text-sm font-medium">Consultar conteúdo proposto</summary>
                                    <div class="mt-4"><x-documentation.content :architecture="$architecture" :content="$request->proposed_content ?? ''" :summary="$request->proposed_architecture_summary ?? ''" :technologies="$request->proposed_tech_stack_json ?? null" :conventions="$request->proposed_conventions_markdown ?? null" /></div>
                                </details>
                                @if ($request->status === 'pending')
                                    @can('review-project-documentation', $this->getRecord())
                                        <div class="docs-stack">
                                            {{ $this->decisionForm }}
                                            <div class="docs-actions">
                                                <x-filament::button color="success" icon="heroicon-o-check" wire:click="decide({{ $request->getKey() }}, 'approved')" wire:confirm="Aprovar esta proposta e publicar uma nova versão?" wire:loading.attr="disabled" wire:target="decide">Aprovar e publicar</x-filament::button>
                                                <x-filament::button color="danger" outlined icon="heroicon-o-x-mark" wire:click="decide({{ $request->getKey() }}, 'rejected')" wire:confirm="Rejeitar esta proposta? O rascunho será preservado." wire:loading.attr="disabled" wire:target="decide">Rejeitar</x-filament::button>
                                            </div>
                                        </div>
                                    @endcan
                                @endif
                                @foreach ($request->decisions as $decision)
                                    <p class="docs-meta">{{ $decision->decision === 'approved' ? 'Aprovada' : 'Rejeitada' }} · {{ $decision->decidedByUser?->name }} · {{ $decision->comments }}</p>
                                @endforeach
                            </div>
                        </x-filament::section>
                    @empty
                        <x-filament::empty-state heading="Nenhuma solicitação" icon="heroicon-o-inbox" description="As solicitações de aprovação desta seção aparecerão aqui." />
                    @endforelse
                </section>
                <section id="docs-panel-history" class="docs-panel" role="tabpanel" aria-labelledby="docs-tab-history" tabindex="0" x-bind:hidden="tab !== 'history'" @if ($this->activeTab !== 'history') hidden @endif>
                    @if (! empty($current['versions']) && $current['versions']->isNotEmpty())
                        <x-filament::section heading="Histórico de versões" description="Consulte versões anteriores sem alterar a versão publicada.">
                            <ol class="docs-version-list">
                                @foreach ($current['versions'] as $version)
                                    <li class="docs-version" wire:key="version-{{ $this->selectedSection }}-{{ $version->getKey() }}">
                                        <div>
                                            <x-filament::link tag="button" wire:click="viewVersion({{ $version->getKey() }})">Versão {{ $version->version_number }} · {{ $version->createdByUser?->name }}</x-filament::link>
                                            @if ($version->changelog)<p class="docs-meta">{{ $version->changelog }}</p>@endif
                                        </div>
                                        <time class="docs-meta">{{ $version->created_at?->format('d/m/Y H:i') }}</time>
                                    </li>
                                @endforeach
                            </ol>
                        </x-filament::section>
                        @if ($historicalVersion = $this->historicalVersion())
                            <x-filament::section :heading="'Versão '.$historicalVersion->version_number" description="Consulta histórica · Somente leitura">
                                <x-documentation.content :architecture="$architecture" :content="$historicalVersion->content_markdown ?? ''" :summary="$historicalVersion->architecture_summary ?? ''" :technologies="$historicalVersion->tech_stack_json ?? null" :conventions="$historicalVersion->conventions_markdown ?? null" />
                            </x-filament::section>
                        @endif
                    @else
                        <x-filament::empty-state heading="Nenhuma versão publicada" icon="heroicon-o-clock" description="O histórico será criado quando uma solicitação for aprovada." />
                    @endif
                </section>
                <footer class="docs-pagination" aria-label="Navegação entre seções">
                    <div>@if ($previous)<x-filament::button color="gray" outlined icon="heroicon-o-arrow-left" @click="navigateTo('{{ $previous }}')">{{ $sections[$previous]['label'] }}</x-filament::button>@endif</div>
                    <div>@if ($next)<x-filament::button color="gray" outlined icon="heroicon-o-arrow-right" icon-position="after" @click="navigateTo('{{ $next }}')">{{ $sections[$next]['label'] }}</x-filament::button>@endif</div>
                </footer>
            </div>
        </div>
    </div>
</x-filament-panels::page>

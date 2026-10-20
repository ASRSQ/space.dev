@props(['architecture' => false, 'content' => '', 'summary' => '', 'technologies' => null, 'conventions' => null])
<div class="docs-stack">
    @if ($architecture)
        <x-documentation.markdown :content="$summary" />
        @if (is_array($technologies) && $technologies !== [])
            <section class="docs-reading" aria-label="Tecnologias">
                <h3 class="text-lg font-semibold">Tecnologias</h3>
                <dl class="docs-technology">
                    @foreach ($technologies as $technology => $value)
                        <div>
                            <dt>{{ $technology }}</dt>
                            <dd>{{ is_scalar($value) ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif
        @if (filled($conventions))
            <section class="docs-stack" aria-label="Convenções">
                <h3 class="docs-reading text-lg font-semibold">Convenções</h3>
                <x-documentation.markdown :content="$conventions" />
            </section>
        @endif
    @else
        <x-documentation.markdown :content="$content" />
    @endif
</div>

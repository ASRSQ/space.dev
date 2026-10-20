@props(['content' => ''])
<div {{ $attributes->class(['fi-prose docs-reading']) }}>
    {{-- Add only fixed accessibility attributes after sanitizing the generated HTML. --}}
    {!! str((string) $content)
        ->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false])
        ->sanitizeHtml()
        ->replace('<pre>', '<pre tabindex="0" aria-label="Bloco de código com rolagem horizontal">')
        ->replace('<table>', '<table tabindex="0" aria-label="Tabela com rolagem horizontal">') !!}
</div>

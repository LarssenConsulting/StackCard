{{-- Data comes from agents: untrusted, printed only through {{ }}. Own styles: this view is not in the panel's CSS build. --}}
@php
    $code = 'margin-top:.25rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.75rem;line-height:1.25rem;'
        .'padding:.75rem;border-radius:.5rem;background:rgb(127 127 127 / .1)';
@endphp
<style>
    .sc-json-key { color: #0550ae; font-weight: 500; }
    .sc-json-string { color: #0a7a3b; }
    .sc-json-number { color: #b35900; }
    .sc-json-literal { color: #8250df; }
    .dark .sc-json-key { color: #79c0ff; }
    .dark .sc-json-string { color: #7ee2a0; }
    .dark .sc-json-number { color: #ffb86b; }
    .dark .sc-json-literal { color: #d2a8ff; }
</style>
<div style="display:grid;gap:1rem">
    @if (filled($error))
        <div>
            <p style="font-size:.875rem;font-weight:500">Erreur</p>
            <pre style="{{ $code }};max-height:12rem"><code>{{ $error }}</code></pre>
        </div>
    @endif
    @if (! empty($sourceErrors))
        <div>
            <p style="font-size:.875rem;font-weight:500">Erreurs de source</p>
            <pre style="{{ $code }};max-height:12rem"><code>{{ \App\Support\JsonHighlighter::html($sourceErrors) }}</code></pre>
        </div>
    @endif
    <div>
        <p style="font-size:.875rem;font-weight:500">Données</p>
        @if ($data === null)
            <p style="margin-top:.25rem;font-size:.875rem;opacity:.7">Aucune donnée.</p>
        @else
            <pre style="{{ $code }};max-height:60vh"><code>{{ \App\Support\JsonHighlighter::html($data) }}</code></pre>
        @endif
    </div>
</div>

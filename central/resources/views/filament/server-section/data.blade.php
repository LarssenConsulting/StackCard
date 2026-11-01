{{-- Data comes from agents: untrusted, printed only through {{ }}. Inline styles: this view is not in the panel's CSS build. --}}
@php
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $code = 'margin-top:.25rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.75rem;line-height:1.25rem;'
        .'padding:.75rem;border-radius:.5rem;background:rgb(127 127 127 / .1)';
@endphp
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
            <pre style="{{ $code }};max-height:12rem"><code>{{ json_encode($sourceErrors, $flags) }}</code></pre>
        </div>
    @endif
    <div>
        <p style="font-size:.875rem;font-weight:500">Données</p>
        @if ($data === null)
            <p style="margin-top:.25rem;font-size:.875rem;opacity:.7">Aucune donnée.</p>
        @else
            <pre style="{{ $code }};max-height:60vh"><code>{{ json_encode($data, $flags) }}</code></pre>
        @endif
    </div>
</div>

{{-- Data comes from agents: untrusted, printed only through {{ }} --}}
<div class="space-y-4">
    @if (filled($error))
        <div>
            <p class="text-sm font-medium">Erreur</p>
            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">{{ $error }}</pre>
        </div>
    @endif
    @if (! empty($sourceErrors))
        <div>
            <p class="text-sm font-medium">Erreurs de source</p>
            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">{{ json_encode($sourceErrors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
    <div>
        <p class="text-sm font-medium">Données</p>
        @if ($data === null)
            <p class="mt-1 text-sm text-gray-500">Aucune donnée.</p>
        @else
            <pre class="mt-1 max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) }}</pre>
        @endif
    </div>
</div>

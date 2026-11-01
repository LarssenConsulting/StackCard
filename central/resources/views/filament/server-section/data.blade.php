{{-- Data comes from agents: untrusted, printed only through {{ }}. Own styles: this view is not in the panel's CSS build. --}}
@php
    use App\Support\JsonHighlighter;

    $label = 'font-size:.875rem;font-weight:600';
@endphp
<style>
    /* Always dark, like a code editor, whatever the panel's theme. */
    .sc-json { margin-top: .375rem; overflow: auto; padding: .75rem 1rem; border-radius: .5rem; background: #1e1e2e; color: #cdd6f4;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; line-height: 1.375rem;
        white-space: pre-wrap; overflow-wrap: anywhere; }
    .sc-json-key { color: #89b4fa; }
    .sc-json-string { color: #a6e3a1; }
    .sc-json-number { color: #fab387; }
    .sc-json-literal { color: #cba6f7; }
    /* Always visible: macOS hides overlay scrollbars until the block is scrolled. */
    .sc-json::-webkit-scrollbar { width: 12px; height: 12px; }
    .sc-json::-webkit-scrollbar-track { background: #313244; border-radius: 6px; }
    .sc-json::-webkit-scrollbar-thumb { background: #7f849c; border-radius: 6px; border: 2px solid #313244; }
    .sc-json::-webkit-scrollbar-thumb:hover { background: #a6adc8; }
    .sc-json::-webkit-scrollbar-corner { background: transparent; }
    @supports not selector(::-webkit-scrollbar) {
        .sc-json { scrollbar-width: auto; scrollbar-color: #7f849c #313244; }
    }
    .sc-json-copy { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; font-size: .8125rem; color: inherit;
        background: transparent; border: 1px solid rgb(127 127 127 / .5); border-radius: .375rem; cursor: pointer; }
    .sc-json-copy:hover { background: rgb(127 127 127 / .15); }
</style>
<div style="display:grid;gap:1rem">
    @if (filled($error))
        <div>
            <p style="{{ $label }}">Erreur</p>
            <pre class="sc-json" style="max-height:12rem">{{ $error }}</pre>
        </div>
    @endif
    @if (! empty($sourceErrors))
        <div>
            <p style="{{ $label }}">Erreurs de source</p>
            <pre class="sc-json" style="max-height:12rem"><code>{{ JsonHighlighter::html($sourceErrors) }}</code></pre>
        </div>
    @endif
    <div data-json-block>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
            <p style="{{ $label }}">Données</p>
            @if ($data !== null)
                <button type="button" class="sc-json-copy" onclick="
                    const button = this, label = button.querySelector('span');
                    const text = button.closest('[data-json-block]').querySelector('code').textContent;
                    const done = (message) => { label.textContent = message; setTimeout(() => { label.textContent = 'Copier'; }, 1500); };
                    const legacy = () => {
                        const area = document.createElement('textarea');
                        area.value = text;
                        button.after(area);
                        area.select();
                        const copied = document.execCommand('copy');
                        area.remove();
                        done(copied ? 'Copié' : 'Échec de la copie');
                    };
                    if (navigator.clipboard) { navigator.clipboard.writeText(text).then(() => done('Copié'), legacy); } else { legacy(); }
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true"><path d="M7 3.5A1.5 1.5 0 0 1 8.5 2h3.879a1.5 1.5 0 0 1 1.06.44l3.122 3.12A1.5 1.5 0 0 1 17 6.622V12.5a1.5 1.5 0 0 1-1.5 1.5h-1v-3.379a3 3 0 0 0-.879-2.121L10.5 5.379A3 3 0 0 0 8.379 4.5H7v-1Z"/><path d="M4.5 6A1.5 1.5 0 0 0 3 7.5v9A1.5 1.5 0 0 0 4.5 18h7a1.5 1.5 0 0 0 1.5-1.5v-5.879a1.5 1.5 0 0 0-.44-1.06L9.44 6.439A1.5 1.5 0 0 0 8.378 6H4.5Z"/></svg>
                    <span>Copier</span>
                </button>
            @endif
        </div>
        @if ($data === null)
            <p style="margin-top:.25rem;font-size:.875rem;opacity:.7">Aucune donnée.</p>
        @else
            <pre class="sc-json" style="max-height:60vh"><code>{{ JsonHighlighter::html($data) }}</code></pre>
        @endif
    </div>
</div>

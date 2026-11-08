{{-- Own styles: this view is not in the panel's CSS build. --}}
<x-filament-panels::page>
    <style>
        .sc-cmd-group { display: grid; gap: .75rem; }
        .sc-cmd-label { font-size: .875rem; margin-bottom: .25rem; }
        .sc-cmd-row { display: flex; align-items: stretch; gap: .5rem; }
        /* Always dark, like a terminal, whatever the panel's theme. */
        .sc-cmd-code { flex: 1; min-width: 0; margin: 0; padding: .5rem .75rem; border-radius: .5rem; background: #1e1e2e; color: #cdd6f4;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; line-height: 1.375rem;
            white-space: pre-wrap; overflow-wrap: anywhere; user-select: all; }
        .sc-cmd-copy { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; font-size: .8125rem; color: inherit;
            background: transparent; border: 1px solid rgb(127 127 127 / .5); border-radius: .375rem; cursor: pointer; white-space: nowrap; }
        .sc-cmd-copy:hover { background: rgb(127 127 127 / .15); }
    </style>

    <div style="display:grid;gap:2rem">
        @foreach ($this->groups() as $group)
            <x-filament::section>
                <x-slot name="heading">{{ $group['title'] }}</x-slot>
                @isset($group['description'])
                    <x-slot name="description">{{ $group['description'] }}</x-slot>
                @endisset

                <div class="sc-cmd-group">
                    @foreach ($group['commands'] as [$label, $command])
                        <div data-command>
                            <p class="sc-cmd-label">{{ $label }}</p>
                            <div class="sc-cmd-row">
                                <pre class="sc-cmd-code"><code>{{ $command }}</code></pre>
                                <button type="button" class="sc-cmd-copy" onclick="
                                    const button = this, label = button.querySelector('span');
                                    const text = button.closest('[data-command]').querySelector('code').textContent;
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
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>

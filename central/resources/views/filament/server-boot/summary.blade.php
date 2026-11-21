{{-- Header of the server's « Boot » tab. Own styles: this view is not in the panel's CSS build. --}}
<style>
    .sc-boot { display: grid; gap: .75rem; margin-top: .5rem; }
    .sc-boot-tiles { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
    .sc-boot-tile { display: flex; flex-direction: column; align-items: flex-start; gap: .375rem; min-width: 0;
        padding: .75rem .875rem; border-radius: .75rem; border: 1px solid rgb(127 127 127 / .22); }
    .sc-boot-label { font-size: .75rem; font-weight: 600; letter-spacing: .02em; text-transform: uppercase; opacity: .65; }
    .sc-boot-value { font-size: 1rem; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .sc-boot-number { font-size: 1.5rem; font-weight: 600; line-height: 1.75rem; font-variant-numeric: tabular-nums; }
    .sc-boot-sub { font-size: .8125rem; opacity: .75; }
    .sc-boot-chips { display: flex; flex-wrap: wrap; gap: .25rem; }
    .sc-boot-chip { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .75rem; line-height: 1.125rem;
        padding: 0 .375rem; border-radius: .375rem; background: rgb(127 127 127 / .14); overflow-wrap: anywhere; }
    .sc-boot-error { padding: .625rem .875rem; border-radius: .75rem; font-size: .8125rem;
        border: 1px solid rgb(220 38 38 / .45); background: rgb(220 38 38 / .08); }
</style>

<div class="sc-boot">
    <div class="sc-boot-tiles">
        <div class="sc-boot-tile">
            <span class="sc-boot-label">Démarré le</span>
            <span class="sc-boot-value">{{ $bootTime ?? '—' }}</span>
            @if ($uptime !== null)
                <span class="sc-boot-sub">en marche depuis {{ $uptime }}</span>
            @endif
        </div>

        <div class="sc-boot-tile">
            <span class="sc-boot-label">Noyau en cours</span>
            <code class="sc-boot-chip">{{ $kernel ?? '—' }}</code>
        </div>

        <div class="sc-boot-tile">
            <span class="sc-boot-label">Redémarrage</span>
            <x-filament::badge :color="$reboot['color']">{{ $reboot['label'] }}</x-filament::badge>
        </div>

        <div class="sc-boot-tile">
            <span class="sc-boot-label">Montés</span>
            @if ($mounted === null)
                <span class="sc-boot-number">—</span>
                <span class="sc-boot-sub">pas encore de données</span>
            @else
                <span class="sc-boot-number">{{ $mounted }}</span>
                @if ($readOnly !== [])
                    <x-filament::badge color="danger">{{ count($readOnly) }} en lecture seule</x-filament::badge>
                    <div class="sc-boot-chips">
                        @foreach ($readOnly as $target)
                            <code class="sc-boot-chip">{{ $target }}</code>
                        @endforeach
                    </div>
                @else
                    <span class="sc-boot-sub">aucun en lecture seule</span>
                @endif
            @endif
            @if ($collectedAt !== null)
                <span class="sc-boot-sub">relevé le {{ $collectedAt }}</span>
            @endif
        </div>
    </div>

    @if ($error !== null)
        <div class="sc-boot-error" role="status">
            <strong>Dernière lecture des montages en erreur :</strong> {{ $error }}
        </div>
    @endif
</div>

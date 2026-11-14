{{-- Header of the server's « Mises à jour » tab. Own styles: this view is not in the panel's CSS build. --}}
<style>
    .sc-upd { display: grid; gap: .75rem; margin-top: .5rem; }
    .sc-upd-tiles { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
    .sc-upd-tile { display: flex; flex-direction: column; align-items: flex-start; gap: .375rem; min-width: 0;
        padding: .75rem .875rem; border-radius: .75rem; border: 1px solid rgb(127 127 127 / .22); }
    .sc-upd-label { font-size: .75rem; font-weight: 600; letter-spacing: .02em; text-transform: uppercase; opacity: .65; }
    .sc-upd-value { display: flex; align-items: baseline; gap: .375rem; flex-wrap: wrap; }
    .sc-upd-number { font-size: 1.5rem; font-weight: 600; line-height: 1.75rem; font-variant-numeric: tabular-nums; }
    .sc-upd-unit { font-size: .875rem; opacity: .8; }
    .sc-upd-sub { font-size: .8125rem; opacity: .75; }
    .sc-upd-chips { display: flex; flex-wrap: wrap; gap: .25rem; }
    .sc-upd-chip { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .75rem; line-height: 1.125rem;
        padding: 0 .375rem; border-radius: .375rem; background: rgb(127 127 127 / .14); overflow-wrap: anywhere; }
    .sc-upd-error { padding: .625rem .875rem; border-radius: .75rem; font-size: .8125rem;
        border: 1px solid rgb(220 38 38 / .45); background: rgb(220 38 38 / .08); }
</style>

<div class="sc-upd">
    <div class="sc-upd-tiles">
        <div class="sc-upd-tile">
            <span class="sc-upd-label">Données</span>
            <x-filament::badge :color="$freshness['color']">{{ $freshness['label'] }}</x-filament::badge>
            <span class="sc-upd-sub">{{ $cache }}</span>
        </div>

        <div class="sc-upd-tile">
            <span class="sc-upd-label">En attente</span>
            @if ($pending === null)
                <span class="sc-upd-number">—</span>
                <span class="sc-upd-sub">pas encore de données</span>
            @else
                <div class="sc-upd-value">
                    <span class="sc-upd-number">{{ $pending }}</span>
                    <span class="sc-upd-unit">{{ $pending === 1 ? 'mise à jour' : 'mises à jour' }}</span>
                </div>
                @if ($security > 0)
                    <x-filament::badge color="danger">{{ $security }} de sécurité</x-filament::badge>
                @else
                    <span class="sc-upd-sub">aucune de sécurité</span>
                @endif
            @endif
        </div>

        <div class="sc-upd-tile">
            <span class="sc-upd-label">Redémarrage</span>
            <x-filament::badge :color="$reboot['color']">{{ $reboot['label'] }}</x-filament::badge>
            @if ($rebootPackages !== [])
                <span class="sc-upd-sub">demandé par</span>
                <div class="sc-upd-chips">
                    @foreach ($rebootPackages as $package)
                        <code class="sc-upd-chip">{{ $package }}</code>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="sc-upd-tile">
            <span class="sc-upd-label">Paquets retenus</span>
            @if ($held === [])
                <span class="sc-upd-sub">aucun</span>
            @else
                <div class="sc-upd-chips">
                    @foreach ($held as $package)
                        <code class="sc-upd-chip">{{ $package }}</code>
                    @endforeach
                </div>
                <span class="sc-upd-sub">bloqués par apt-mark hold</span>
            @endif
        </div>
    </div>

    @if ($error !== null)
        <div class="sc-upd-error" role="status">
            <strong>Dernière collecte en erreur :</strong> {{ $error }}
        </div>
    @endif
</div>

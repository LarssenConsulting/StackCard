@php
    $steps = [
        [1, 'Lancer un modèle', 'playbook, variables, Limit', false, 64],
        [2, 'Cloner le dépôt', 'playbooks et script', false, 164],
        [3, 'Lire l’inventaire', 'stackcard_inventory.py', true, 264],
        [4, 'ansible-playbook', 'compte ansible, sudo', false, 364],
    ];
@endphp
<figure class="diagram">
    <svg class="dg" viewBox="0 0 600 470" role="img" aria-labelledby="sema-title sema-desc">
        <title id="sema-title">Une exécution Semaphore avec l’inventaire StackCard</title>
        <desc id="sema-desc">1 : dans Semaphore, on lance un modèle de tâche (playbook, variables, Limit), qui s’appuie sur le Key Store et le Variable Group. 2 : Semaphore clone le dépôt de playbooks en HTTPS avec un jeton en lecture seule. 3 : il exécute le script stackcard_inventory.py, qui appelle l’API StackCard (GET /api/v1/inventory/ansible, avec jeton et adresse IP autorisée) et reçoit en JSON les hôtes, nommés par leur slug, et leurs groupes. 4 : ansible-playbook se connecte en SSH aux serveurs avec le compte ansible, clés d’hôte vérifiées.</desc>
        <defs>
            <marker id="sema-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head"/></marker>
            <marker id="sema-hs" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head sema"/></marker>
            <marker id="sema-hc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head central"/></marker>
        </defs>

        <rect class="box k-sema" x="16" y="12" width="280" height="446" rx="18"/>
        <text class="t-title t-center" x="156" y="44" style="fill: var(--sema)">Semaphore UI</text>
        @foreach ($steps as [$n, $title, $sub, $mono, $y])
            <rect class="box k-plain" x="32" y="{{ $y }}" width="248" height="84" rx="12"/>
            <circle class="c-sema" cx="58" cy="{{ $y + 30 }}" r="14"/>
            <text class="num" x="58" y="{{ $y + 35 }}">{{ $n }}</text>
            <text class="t-title" x="82" y="{{ $y + 36 }}">{{ $title }}</text>
            <text class="{{ $mono ? 't-mono' : 't-sub' }}" x="48" y="{{ $y + 66 }}">{{ $sub }}</text>
            @if ($n < 4)
                <path class="arrow sema" d="M156 {{ $y + 84 }} L156 {{ $y + 98 }}" marker-end="url(#sema-hs)"/>
            @endif
        @endforeach

        {{-- Settings held by Semaphore --}}
        <rect class="group" x="344" y="64" width="240" height="84" rx="12"/>
        <text class="t-title" x="362" y="96">Key Store · Variables</text>
        <text class="t-sub" x="362" y="120">clé SSH, jeton du dépôt,</text>
        <text class="t-sub" x="362" y="139">STACKCARD_URL / _TOKEN</text>
        <path class="arrow dashed" d="M344 106 L284 106" marker-end="url(#sema-h)"/>

        {{-- Playbook repository --}}
        <rect class="box k-srv" x="344" y="164" width="240" height="84" rx="12"/>
        <text class="t-title" x="362" y="196">Dépôt de playbooks</text>
        <text class="t-sub" x="362" y="220">HTTPS, jeton en lecture</text>
        <text class="t-sub" x="362" y="239">seule</text>
        <path class="arrow" d="M284 206 L340 206" marker-start="url(#sema-h)" marker-end="url(#sema-h)"/>
        <text class="t-label t-halo t-center" x="318" y="198">clone</text>

        {{-- StackCard API --}}
        <rect class="box k-central" x="344" y="264" width="240" height="84" rx="12"/>
        <text class="t-title" x="362" y="294">API StackCard</text>
        <text class="t-mono" x="362" y="316">GET …/inventory/ansible</text>
        <text class="t-sub" x="362" y="337">jeton + IP autorisée</text>
        <path class="arrow central" d="M284 294 L340 294" marker-end="url(#sema-hc)"/>
        <text class="t-label t-halo t-center" x="318" y="287">GET</text>
        <path class="arrow central" d="M340 320 L284 320" marker-end="url(#sema-hc)"/>
        <text class="t-label t-halo t-center" x="318" y="338">JSON</text>

        {{-- Servers --}}
        <rect class="box k-srv" x="344" y="364" width="240" height="84" rx="12"/>
        <text class="t-title" x="362" y="396">web01, db01…</text>
        <text class="t-sub" x="362" y="420">hôtes = slugs StackCard,</text>
        <text class="t-sub" x="362" y="439">clés d’hôte vérifiées</text>
        <path class="arrow sema" d="M284 406 L340 406" marker-end="url(#sema-hs)"/>
        <text class="t-label t-halo t-center" x="318" y="398" style="fill: var(--sema)">SSH</text>
    </svg>
    <figcaption>Semaphore ne garde aucune liste de serveurs : à chaque exécution, le script redemande l’inventaire à StackCard.</figcaption>
</figure>

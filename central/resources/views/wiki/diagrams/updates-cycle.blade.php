@php
    $steps = [
        [1, 'agent', 'L’agent détecte', 'simulation apt-get -s,', 'rien n’est installé', 20, 40],
        [2, 'central', 'StackCard affiche', 'badges, page Mises à jour,', 'groupes Ansible', 330, 40],
        [3, 'sema', 'Semaphore applique', 'apt-upgrade.yml, par ex.', 'sur security_updates', 330, 190],
        [4, 'agent', 'L’agent confirme', 'discover-agent run en', 'fin de playbook', 20, 190],
    ];
@endphp
<figure class="diagram">
    <svg class="dg" viewBox="0 0 600 334" role="img" aria-labelledby="upd-title upd-desc">
        <title id="upd-title">Le cycle des mises à jour APT</title>
        <desc id="upd-desc">1 : l’agent détecte les mises à jour en attente par une simulation apt-get, sans rien installer. 2 : StackCard les affiche (badges, page Mises à jour) et les traduit en groupes Ansible. 3 : Semaphore les applique, par exemple avec apt-upgrade.yml sur le groupe security_updates. 4 : en fin de playbook, l’agent envoie un inventaire avec discover-agent run, et StackCard montre l’état à jour. Le cycle recommence.</desc>
        <defs>
            <marker id="upd-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head"/></marker>
        </defs>
        <path class="arrow" d="M270 95 L326 95" marker-end="url(#upd-h)"/>
        <path class="arrow" d="M455 150 L455 186" marker-end="url(#upd-h)"/>
        <path class="arrow" d="M330 245 L274 245" marker-end="url(#upd-h)"/>
        <path class="arrow" d="M145 190 L145 154" marker-end="url(#upd-h)"/>
        @foreach ($steps as [$n, $key, $title, $line1, $line2, $x, $y])
            <rect class="box k-{{ $key }}" x="{{ $x }}" y="{{ $y }}" width="250" height="110" rx="14"/>
            <circle class="c-{{ $key }}" cx="{{ $x + 28 }}" cy="{{ $y + 31 }}" r="15"/>
            <text class="num" x="{{ $x + 28 }}" y="{{ $y + 36 }}">{{ $n }}</text>
            <text class="t-title" x="{{ $x + 52 }}" y="{{ $y + 37 }}">{{ $title }}</text>
            <text class="t-sub" x="{{ $x + 18 }}" y="{{ $y + 68 }}">{{ $line1 }}</text>
            <text class="t-sub" x="{{ $x + 18 }}" y="{{ $y + 90 }}">{{ $line2 }}</text>
        @endforeach
        <text class="t-sub t-center" x="300" y="326">StackCard observe ; seul un playbook que vous lancez applique.</text>
    </svg>
    <figcaption>Vert : l’agent. Indigo : le central. Orange : Semaphore et Ansible.</figcaption>
</figure>

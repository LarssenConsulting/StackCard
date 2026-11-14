@php
    // [number, colour key, title, line 1, line 2, x, y]
    $steps = [
        [1, 'agent', 'Timer systemd', 'toutes les heures,', '± 5 min de décalage', 20, 50],
        [2, 'agent', 'Lecture seule', 'Apache, PHP, MySQL,', 'Docker, Node, UFW…', 20, 190],
        [3, 'agent', 'Sections', '15 sections d’état,', 'dont 2 hebdomadaires', 20, 330],
        [4, 'agent', 'Masquage', 'mots de passe, jetons :', 'retirés ou « *** »', 310, 330],
        [5, 'central', 'Envoi HTTPS', 'POST vers le central,', 'certificat vérifié', 310, 190],
        [6, 'central', 'Réponse « ok »', 'le central stocke,', 'aucun ordre en retour', 310, 50],
    ];
@endphp
<figure class="diagram">
    <svg class="dg" viewBox="0 0 560 450" role="img" aria-labelledby="cycle-title cycle-desc">
        <title id="cycle-title">Un passage horaire de l’agent</title>
        <desc id="cycle-desc">Six étapes en boucle. 1 : le timer systemd lance l’agent toutes les heures, avec jusqu’à 5 minutes de décalage aléatoire. 2 : l’agent lit la configuration sans rien modifier. 3 : il construit 15 sections d’état, dont 2 hebdomadaires. 4 : il masque les secrets. 5 : il envoie l’inventaire en HTTPS au central, certificat vérifié. 6 : le central répond « ok » et ne renvoie aucun ordre. Une heure plus tard, le cycle recommence.</desc>
        <defs>
            <marker id="cycle-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head"/></marker>
        </defs>

        <path class="arrow" d="M135 146 L135 184" marker-end="url(#cycle-h)"/>
        <path class="arrow" d="M135 286 L135 324" marker-end="url(#cycle-h)"/>
        <path class="arrow" d="M250 378 L304 378" marker-end="url(#cycle-h)"/>
        <path class="arrow" d="M425 330 L425 292" marker-end="url(#cycle-h)"/>
        <path class="arrow" d="M425 190 L425 152" marker-end="url(#cycle-h)"/>
        <path class="arrow dashed" d="M425 50 L425 24 L135 24 L135 44" marker-end="url(#cycle-h)"/>
        <rect x="236" y="11" width="88" height="26" rx="13" style="fill: var(--surface)"/>
        <text class="t-label t-center" x="280" y="29">+ 1 heure</text>

        @foreach ($steps as [$n, $key, $title, $line1, $line2, $x, $y])
            <rect class="box k-{{ $key }}" x="{{ $x }}" y="{{ $y }}" width="230" height="96" rx="14"/>
            <circle class="c-{{ $key }}" cx="{{ $x + 28 }}" cy="{{ $y + 30 }}" r="15"/>
            <text class="num" x="{{ $x + 28 }}" y="{{ $y + 35 }}">{{ $n }}</text>
            <text class="t-title" x="{{ $x + 52 }}" y="{{ $y + 36 }}">{{ $title }}</text>
            <text class="t-sub" x="{{ $x + 18 }}" y="{{ $y + 64 }}">{{ $line1 }}</text>
            <text class="t-sub" x="{{ $x + 18 }}" y="{{ $y + 84 }}">{{ $line2 }}</text>
        @endforeach
    </svg>
    <figcaption>Vert : ce qui se passe sur le serveur. Indigo : l’échange avec le central. L’agent n’ouvre aucun port : c’est toujours lui qui appelle.</figcaption>
</figure>

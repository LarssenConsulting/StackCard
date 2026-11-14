@php
    // [state colour, title, code, rule line 1, rule line 2, badges [[text, colour, width]]]
    $rows = [
        ['ok', 'ok', 'fiable', 'relevé valide et cache APT', 'de moins de 24 h',
            [['à jour', 'ok', 70], ['3', 'warn', 36], ['5 dont 2 sécu', 'danger', 124]]],
        ['warn', 'périmé', 'stale', 'dernier envoi en erreur, ou cache', 'APT > 24 h : données gardées, datées',
            [['à jour (au 12/11)', 'gray', 152], ['4 (au 12/11)', 'warn', 116]]],
        ['gray', 'inconnu', 'unknown', 'aucun relevé valide reçu (agent', 'antérieur à 0.6, ou que des erreurs)',
            [['inconnu', 'gray', 82]]],
    ];
@endphp
<figure class="diagram">
    <svg class="dg" viewBox="0 0 600 350" role="img" aria-labelledby="fresh-title fresh-desc">
        <title id="fresh-title">États de fraîcheur des mises à jour et badges affichés</title>
        <desc id="fresh-desc">Trois états. « ok » : relevé valide et cache APT de moins de 24 heures ; badges « à jour » en vert, un nombre en orange, ou « 5 dont 2 sécu » en rouge quand il y a des mises à jour de sécurité. « périmé » : dernier envoi en erreur ou cache APT de plus de 24 heures ; les dernières données sont gardées avec leur date, par exemple « à jour (au 12/11) » en gris ou « 4 (au 12/11) ». « inconnu » : aucun relevé valide, badge gris « inconnu ».</desc>
        @foreach ($rows as $i => [$color, $title, $code, $rule1, $rule2, $badges])
            @php($y = 14 + $i * 112)
            <rect class="box k-plain" x="10" y="{{ $y }}" width="580" height="100" rx="14"/>
            <rect class="box k-{{ $color }}" x="10" y="{{ $y }}" width="150" height="100" rx="14"/>
            <text class="t-title" x="30" y="{{ $y + 44 }}" style="fill: var(--{{ $color }}); font-size: 20px">{{ $title }}</text>
            <text class="t-mono" x="30" y="{{ $y + 70 }}">{{ $code }}</text>
            <text class="t-sub" x="180" y="{{ $y + 30 }}" style="fill: var(--ink)">{{ $rule1 }}</text>
            <text class="t-sub" x="180" y="{{ $y + 50 }}" style="fill: var(--ink)">{{ $rule2 }}</text>
            @php($x = 180)
            @foreach ($badges as [$text, $badge, $width])
                <rect class="k-{{ $badge }}" x="{{ $x }}" y="{{ $y + 62 }}" width="{{ $width }}" height="26" rx="13" stroke="none"/>
                <text class="t-label" x="{{ $x + 12 }}" y="{{ $y + 80 }}" style="fill: var(--{{ $badge }})">{{ $text }}</text>
                @php($x += $width + 10)
            @endforeach
        @endforeach
    </svg>
    <figcaption>« à jour » n’est vert que dans l’état « ok ». Un serveur périmé à zéro mise à jour reste gris : ses chiffres datent.</figcaption>
</figure>

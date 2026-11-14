<figure class="diagram">
    <svg class="dg" viewBox="0 40 720 432" role="img" aria-labelledby="arch-title arch-desc">
        <title id="arch-title">Architecture de StackCard</title>
        <desc id="arch-desc">Sur chaque serveur Ubuntu (web01, web02, db01), un agent envoie son inventaire en HTTPS, une fois par heure, à la réception du central StackCard. Le central offre l’administration (/admin) et une API de lecture. L’outil de suivi des CVE lit l’API. Semaphore lit l’inventaire dynamique dans l’API, puis Ansible se connecte aux serveurs en SSH pour exécuter les playbooks. Le central ne contacte jamais les agents.</desc>
        <defs>
            <marker id="arch-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head"/></marker>
            <marker id="arch-ha" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head agent"/></marker>
            <marker id="arch-hs" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head sema"/></marker>
        </defs>

        {{-- Servers and their agents --}}
        <rect class="group" x="16" y="56" width="190" height="340" rx="16"/>
        <text class="t-title t-center" x="111" y="84">Serveurs Ubuntu</text>
        @foreach ([['web01', 100], ['web02', 196], ['db01', 292]] as [$name, $y])
            <rect class="box k-srv" x="32" y="{{ $y }}" width="158" height="80" rx="12"/>
            <text class="t-title" x="48" y="{{ $y + 30 }}">{{ $name }}</text>
            <rect class="box k-agent" x="48" y="{{ $y + 44 }}" width="76" height="24" rx="12"/>
            <text class="t-label t-center" x="86" y="{{ $y + 61 }}" style="fill: var(--agent)">agent</text>
            <rect class="c-srv" x="150" y="{{ $y + 18 }}" width="26" height="6" rx="2" opacity=".55"/>
            <rect class="c-srv" x="150" y="{{ $y + 30 }}" width="26" height="6" rx="2" opacity=".55"/>
            <rect class="c-srv" x="150" y="{{ $y + 42 }}" width="26" height="6" rx="2" opacity=".55"/>
        @endforeach

        {{-- Agents push to the central --}}
        <text class="t-label t-center" x="253" y="112">HTTPS</text>
        <text class="t-label t-center" x="253" y="129">1 fois/h</text>
        <path class="arrow agent" d="M190 140 C 250 140, 250 334, 312 334" marker-end="url(#arch-ha)"/>
        <path class="arrow agent" d="M190 236 C 245 236, 255 340, 312 340" marker-end="url(#arch-ha)"/>
        <path class="arrow agent" d="M190 332 C 240 332, 260 346, 312 346" marker-end="url(#arch-ha)"/>

        {{-- Central --}}
        <rect class="box k-central" x="300" y="56" width="200" height="340" rx="16"/>
        <text class="t-title t-center" x="400" y="84">Central StackCard</text>
        <text class="t-sub t-center" x="400" y="104">Laravel · Filament</text>
        <rect class="box k-plain" x="316" y="122" width="168" height="74" rx="10"/>
        <text class="t-title t-center" x="400" y="152">Administration</text>
        <text class="t-mono t-center" x="400" y="175">/admin</text>
        <rect class="box k-plain" x="316" y="212" width="168" height="74" rx="10"/>
        <text class="t-title t-center" x="400" y="242">API de lecture</text>
        <text class="t-mono t-center" x="400" y="265">GET /api/v1/…</text>
        <rect class="box k-plain" x="316" y="302" width="168" height="74" rx="10"/>
        <text class="t-title t-center" x="400" y="332">Réception</text>
        <text class="t-mono t-center" x="400" y="355">POST …/inventory</text>

        {{-- CVE tracking tool --}}
        <rect class="box k-cve" x="560" y="56" width="148" height="120" rx="14"/>
        <text class="t-title t-center" x="634" y="90">Outil de suivi</text>
        <text class="t-title t-center" x="634" y="110">des CVE</text>
        <text class="t-sub t-center" x="634" y="140">paquets, versions,</text>
        <text class="t-sub t-center" x="634" y="159">mises à jour</text>
        <path class="arrow" d="M488 236 C 528 236, 520 128, 556 128" marker-start="url(#arch-h)" marker-end="url(#arch-h)"/>
        <text class="t-label t-halo t-center" x="524" y="196">lecture</text>

        {{-- Semaphore / Ansible --}}
        <rect class="box k-sema" x="560" y="236" width="148" height="160" rx="14"/>
        <text class="t-title t-center" x="634" y="266">Semaphore UI</text>
        <rect class="box k-plain" x="574" y="284" width="120" height="62" rx="10"/>
        <text class="t-title t-center" x="634" y="311">Ansible</text>
        <text class="t-sub t-center" x="634" y="332">playbooks</text>
        <text class="t-sub t-center" x="634" y="376">hôtes = slugs</text>
        <path class="arrow" d="M488 262 C 528 262, 520 316, 556 316" marker-end="url(#arch-h)"/>
        <text class="t-label t-halo t-center" x="520" y="284">inventaire</text>

        {{-- Ansible reaches the servers over SSH --}}
        <path class="arrow sema" d="M634 396 L634 432 L111 432 L111 400" marker-end="url(#arch-hs)"/>
        <rect x="282" y="420" width="180" height="24" rx="12" style="fill: var(--surface)"/>
        <text class="t-label t-center" x="372" y="437" style="fill: var(--sema)">SSH · playbooks Ansible</text>
        <text class="t-sub t-center" x="360" y="464">Le central ne contacte jamais les agents : il reçoit, stocke et expose.</text>
    </svg>
    <figcaption>Les agents poussent leurs données vers le central ; l’outil de suivi des CVE et Semaphore lisent l’API ; seul Ansible agit sur les serveurs, en SSH.</figcaption>
</figure>

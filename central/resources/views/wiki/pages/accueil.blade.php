@extends('wiki.layout')

@section('content')
    <h2 id="quoi">StackCard en bref</h2>
    <p>
        StackCard tient l’<strong>inventaire centralisé</strong> de serveurs Ubuntu (22.04, 24.04 et 26.04) : nom,
        adresses IP, sites Apache et leurs domaines, bases MySQL, comptes système et de base de données liés à chaque
        site, technologies, conteneurs Docker, pare-feu, mises à jour APT en attente…
    </p>
    <p>
        Il est la <strong>source de vérité</strong> des serveurs et des domaines : un serveur enrôlé apparaît, un serveur
        révoqué sort des listes, sans tableau parallèle à maintenir. Les autres outils (Ansible, suivi des CVE) s’appuient
        sur son identifiant stable, le <strong>slug</strong> (par exemple <code>web01</code>).
    </p>

    <h2 id="briques">Les briques</h2>
    <div class="cards">
        <div class="card b-agent">
            <h3>L’agent</h3>
            <p>Sur chaque serveur. Lit la configuration sans rien modifier et envoie un inventaire toutes les heures.</p>
            <a class="more" href="{{ route('wiki.page', 'agent', false) }}">L’agent →</a>
        </div>
        <div class="card b-central">
            <h3>Le central</h3>
            <p>Application Laravel et Filament : reçoit les inventaires, les stocke et les présente dans l’administration.</p>
            <a class="more" href="{{ route('wiki.page', 'central', false) }}">Central et admin →</a>
        </div>
        <div class="card b-central">
            <h3>L’API de lecture</h3>
            <p>Pour les autres applications : serveurs, domaines, mises à jour, paquets et inventaire Ansible, en lecture seule.</p>
            <a class="more" href="{{ route('wiki.page', 'api', false) }}">L’API →</a>
        </div>
        <div class="card b-sema">
            <h3>Semaphore et Ansible</h3>
            <p>Semaphore lit l’inventaire dynamique de StackCard ; Ansible agit sur les serveurs en SSH (mises à jour, agent…).</p>
            <a class="more" href="{{ route('wiki.page', 'ansible-semaphore', false) }}">Ansible et Semaphore →</a>
        </div>
        <div class="card b-cve">
            <h3>Le suivi des CVE</h3>
            <p>Un outil externe interroge l’API : qui a tel paquet, en quelle version, une mise à jour est-elle proposée ?</p>
            <a class="more" href="{{ route('wiki.page', 'api', false) }}#routes">Les routes →</a>
        </div>
    </div>

    <h2 id="architecture">Architecture</h2>
    @include('wiki.diagrams.architecture')

    <h2 id="push-only">Le principe push-only</h2>
    <p>
        L’agent <strong>envoie</strong>, le central <strong>n’ordonne jamais</strong>. L’agent n’ouvre aucun port et
        n’accepte aucune commande : à chaque envoi, le central répond seulement <code>{"status": "ok"}</code>.
    </p>
    <ul>
        <li>Rien ne s’exécute sur un serveur depuis StackCard : ni installation, ni mise à jour, ni redémarrage.</li>
        <li>Quand il faut agir (mettre à jour les paquets, installer l’agent), c’est <strong>Ansible</strong>, lancé
            depuis Semaphore, qui le fait, en ciblant les serveurs grâce à l’inventaire de StackCard.</li>
        <li>Un réglage de l’agent (par exemple la fréquence de rafraîchissement du cache APT) se change sur le serveur,
            à la main ou par Ansible, jamais depuis le central.</li>
    </ul>

    <h2 id="protections">Protections</h2>
    <div class="callout">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1.5 3 4.5v5c0 4.2 2.9 7.8 7 9 4.1-1.2 7-4.8 7-9v-5l-7-3Z"/></svg>
        <ul>
            <li><strong>Agents</strong> : enrôlement par un jeton à usage unique, valable 24 h ; envois en HTTPS, certificat toujours vérifié.</li>
            <li><strong>Administration</strong> : comptes nominatifs, double authentification par application (TOTP) obligatoire, rôles admin et utilisateur.</li>
            <li><strong>API de lecture</strong> : un jeton par application cliente, valable seulement depuis les adresses IP autorisées pour lui.</li>
            <li><strong>Ansible</strong> : seuls les serveurs actifs dont l’adresse a été validée sont dans l’inventaire, et les clés d’hôte SSH restent vérifiées.</li>
        </ul>
    </div>

    <h2 id="lire-la-suite">Par où continuer</h2>
    <ul>
        <li>Installer un premier serveur : <a href="{{ route('wiki.page', 'procedures', false) }}#nouveau-serveur">Ajouter un nouveau serveur</a>.</li>
        <li>Comprendre ce que l’agent lit et envoie : <a href="{{ route('wiki.page', 'agent', false) }}">L’agent</a>.</li>
        <li>Brancher Semaphore : <a href="{{ route('wiki.page', 'ansible-semaphore', false) }}#semaphore">Configurer Semaphore</a>.</li>
    </ul>
@endsection

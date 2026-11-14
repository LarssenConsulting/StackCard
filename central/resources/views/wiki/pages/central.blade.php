@extends('wiki.layout')

@section('content')
    <h2 id="acces">Accès et rôles</h2>
    <p>
        L’administration est sous <a href="/admin"><code>/admin</code></a>. Chaque compte est nominatif et doit enregistrer
        une application d’authentification (double authentification obligatoire). Deux rôles :
    </p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Rôle</th><th scope="col">Peut</th></tr></thead>
            <tbody>
                <tr><td><span class="badge">user</span></td><td>tout consulter : serveurs, sites, bases, domaines, mises à jour, commandes de l’agent.</td></tr>
                <tr><td><span class="badge ok">admin</span></td><td>en plus : le groupe « Réglages » (utilisateurs, clients API, réglages généraux), les jetons d’enrôlement, le slug, l’adresse et le port de connexion, l’exclusion d’Ansible, la validation d’une nouvelle IP, les commandes de maintenance du central.</td></tr>
            </tbody>
        </table>
    </div>
    <p>Le premier compte créé (<code>php artisan make:filament-user</code>) est admin. Un admin ne peut ni se supprimer ni changer son propre rôle : il en reste toujours un.</p>

    <h2 id="liste">La liste des serveurs</h2>
    <p>Une ligne par serveur. Les colonnes qui demandent un peu d’explication :</p>
    <dl class="defs">
        <dt>Slug</dt>
        <dd>L’identifiant stable du serveur, copiable. La mention « non figé » signale un slug encore modifiable (voir <a href="#slug">Le slug</a>).</dd>
        <dt>Adresse</dt>
        <dd>L’adresse utilisée par Ansible : l’adresse de connexion saisie à la main (« manuelle »), sinon l’IP principale remontée par l’agent. En orange : « IP changée, à valider ».</dd>
        <dt>Ansible</dt>
        <dd>
            <span class="badge ok">oui</span> dans l’inventaire ·
            <span class="badge">exclu</span> par un admin ·
            <span class="badge warn">IP à valider</span> ·
            <span class="badge danger">sans adresse</span> ·
            <span class="badge">non</span> serveur en attente ou révoqué. Le survol donne la raison.
        </dd>
        <dt>Mises à jour</dt>
        <dd>Le nombre de mises à jour APT en attente, avec « redémarrage requis » si besoin (voir <a href="#mises-a-jour">Fraîcheur des mises à jour</a>). Le survol donne l’âge du cache APT.</dd>
        <dt>Agent</dt>
        <dd>La version de l’agent. En orange quand une version plus récente est publiée : un clic montre comment mettre à jour.</dd>
        <dt>État</dt>
        <dd><span class="badge">En attente d’enrôlement</span> <span class="badge ok">Actif</span> <span class="badge warn">Silencieux</span> (aucun envoi depuis 3 h) <span class="badge danger">Révoqué</span></dd>
    </dl>
    <p>Filtres : état, « Mises à jour de sécurité », « Redémarrage requis ». En haut de la liste, un admin trouve « Figer les slugs » tant qu’il reste des slugs à figer.</p>

    <h2 id="cycle-de-vie">Cycle de vie d’un serveur</h2>
    @include('wiki.diagrams.server-lifecycle')
    <ul>
        <li><strong>Révoquer</strong> (fiche du serveur) : l’agent ne peut plus envoyer, les données actuelles sont conservées.</li>
        <li><strong>Réenrôler</strong> : générer un nouveau jeton sur la <strong>même fiche</strong>. Un serveur révoqué repasse « En attente » ; dans tous les cas la fiche, donc le slug, est conservée.</li>
        <li>Un même <code>machine-id</code> ne peut pas être enrôlé sous deux fiches : l’enrôlement est refusé tant que l’ancienne fiche existe.</li>
    </ul>

    <h2 id="fiche">La fiche d’un serveur</h2>
    <p>En tête : identité (nom, slug, adresse de connexion, port SSH, état Ansible, hostname, FQDN, IP principale et IP validée), système, agent. Puis des onglets :</p>
    <div class="cards">
        <div class="card b-central"><h3>Sites</h3><p>Vhosts Apache, type (PHP-FPM, Node, Docker, proxy, statique), utilisateur système, bases liées, technologies.</p></div>
        <div class="card b-central"><h3>Mises à jour</h3><p>Une ligne par paquet : installé, disponible, sécurité, origine ; en tête l’âge du cache, le redémarrage requis, les paquets bloqués.</p></div>
        <div class="card b-central"><h3>Applications Node · Conteneurs Docker</h3><p>Processus Node en écoute et conteneurs, rattachés aux sites qui les servent.</p></div>
        <div class="card b-central"><h3>Pare-feu</h3><p>Une table par moteur : UFW, nftables, iptables, Fail2Ban.</p></div>
        <div class="card b-central"><h3>Bases de données · Comptes MySQL</h3><p>Bases, tailles, comptes et droits (jamais les empreintes de mot de passe).</p></div>
        <div class="card b-central"><h3>État détaillé · Historique des envois</h3><p>Les sections, avec leur historique de changements, et les derniers inventaires reçus.</p></div>
    </div>
    <p>Actions d’en-tête : « Générer un jeton d’enrôlement », « Valider la nouvelle IP », « Changer le slug » (selon le cas, admins), « Révoquer », « Modifier ».</p>

    <h2 id="slug">Le slug</h2>
    <p>
        Le slug (<code>web01</code>, <code>db-paris-1</code>…) est l’identifiant que <strong>tous les outils</strong>
        utilisent : nom d’hôte dans Ansible, clé de rapprochement de l’outil de suivi des CVE, paramètre des routes de
        l’API. Format : minuscules, chiffres et tirets, 64 caractères au plus, jamais uniquement des chiffres.
    </p>
    <ul>
        <li>Proposé à partir du nom à la création, modifiable <strong>tant que le serveur n’a jamais envoyé d’inventaire</strong>.</li>
        <li><strong>Figé</strong> au premier inventaire reçu. Renommer le serveur ne change pas son slug.</li>
        <li><strong>« Figer les slugs »</strong> (liste des serveurs, admins, avec confirmation) fige d’un coup les slugs encore modifiables des serveurs qui ont déjà envoyé un inventaire. À faire avant de brancher Semaphore ou l’outil de suivi des CVE.</li>
        <li><strong>« Changer le slug »</strong> (fiche, admins) reste possible pour un slug figé, en confirmant qu’on mettra à jour les outils qui utilisent l’ancien.</li>
        <li>Un serveur révoqué garde son slug ; seule la suppression du serveur le libère.</li>
    </ul>

    <h2 id="ip">Adresse et IP validée</h2>
    <p>
        Ansible se connecte à l’<strong>adresse de connexion</strong> si un admin l’a saisie (utile derrière un NAT ou un
        VPN, avec un éventuel port SSH), sinon à l’IP principale remontée par l’agent, <strong>tant qu’elle est l’IP
        validée</strong>. L’IP validée est fixée au premier inventaire.
    </p>
    <div class="callout warn">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 13H9v-2h2v2Zm0-4H9V7h2v4Z"/></svg>
        <p>
            Si l’agent signale ensuite une autre IP, l’adresse passe en orange et le serveur <strong>sort de l’inventaire
            Ansible</strong> jusqu’à ce qu’un admin clique « Valider la nouvelle IP » (liste ou fiche). Les identifiants SSH
            de Semaphore ne partent ainsi jamais vers une adresse que personne n’a vérifiée.
        </p>
    </div>

    <h2 id="exclusion">Exclure un serveur d’Ansible</h2>
    <p>
        Fiche du serveur → <em>Modifier</em> → « Exclure de l’inventaire Ansible » (admins). Par exemple pour un bastion
        joignable seulement par VPN, qui ne doit pas recevoir la clé de Semaphore. Le serveur reste visible partout
        ailleurs, dans l’administration et dans l’API.
    </p>

    <h2 id="mises-a-jour">Fraîcheur des mises à jour</h2>
    <p>
        Un chiffre de mises à jour n’a de sens que si le cache APT du serveur est récent. Chaque serveur a donc un état de
        fraîcheur, le même partout (administration, API, inventaire Ansible) :
    </p>
    @include('wiki.diagrams.updates-freshness')
    <ul>
        <li>Une section en erreur ne remplace pas les dernières données valides : elles restent affichées, avec leur date (« au 12/11 »).</li>
        <li>« inconnu (erreur) » : données périmées, aucune mise à jour connue et dernier envoi en erreur.</li>
        <li>« redémarrage requis » est affiché sous le badge, indépendamment de l’état.</li>
    </ul>

    <h2 id="pages">Domaines, Mises à jour, Commandes</h2>
    <dl class="defs">
        <dt>Domaines</dt>
        <dd>Chaque <code>ServerName</code> et alias des sites Apache de la flotte, un par ligne : serveur(s), ports, HTTPS, expiration du certificat, type, technologies. Lecture seule, tous les rôles.</dd>
        <dt>Mises à jour</dt>
        <dd>La vue flotte : une ligne par paquet et version disponible, avec les versions installées, la sécurité et les serveurs concernés (liens vers leurs fiches). Filtre sécurité, recherche par paquet. Aucun bouton d’action : StackCard n’applique rien.</dd>
        <dt>Commandes</dt>
        <dd>Les commandes de l’agent prêtes à copier (installer, mettre à jour, lancer, vérifier, désinstaller), avec l’adresse de ce central ; les jetons restent des espaces réservés. Les admins y trouvent aussi la maintenance du central.</dd>
    </dl>
@endsection

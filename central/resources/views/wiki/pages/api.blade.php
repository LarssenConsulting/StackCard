@extends('wiki.layout')

@php
    $routes = [
        ['/api/v1/servers', 'Tous les serveurs : slug, nom, hostname, FQDN, adresses, état, fraîcheur, compteurs de mises à jour.'],
        ['/api/v1/servers/{server}', 'Un serveur, par son slug (ou son identifiant numérique) : sites, bases, sections reçues…'],
        ['/api/v1/servers/{server}/sections/{section}', 'Le dernier état d’une section (par exemple packages, kernel, network).'],
        ['/api/v1/servers/{server}/updates', 'Le détail des mises à jour APT d’un serveur : paquets, bloqués, redémarrage, âge du cache.'],
        ['/api/v1/updates', 'La vue flotte par paquet et version disponible. Filtres ?security=1 et ?package=<nom>.'],
        ['/api/v1/packages?name=<nom>', 'Où un paquet est installé, en quelle version, et si une version plus récente est proposée.'],
        ['/api/v1/domains', 'Tous les domaines servis, avec où chacun est servi. Filtre ?server=<slug>.'],
        ['/api/v1/inventory/ansible', 'L’inventaire dynamique Ansible (hôtes = slugs, groupes, variables).'],
    ];
@endphp

@section('content')
    <h2 id="principe">Principe</h2>
    <p>
        L’API de lecture sert les autres applications : l’outil de suivi des CVE, Semaphore, un script maison. Elle est
        <strong>en lecture seule</strong> (uniquement des <code>GET</code>) et ne donne aucun verdict : elle expose
        l’inventaire, l’évaluation revient au consommateur.
    </p>
    <div class="cards">
        <div class="card b-central"><h3>Un jeton par client</h3><p>Créé par un admin, affiché une seule fois, valable 365 jours au plus, avec la seule capacité de lecture.</p></div>
        <div class="card b-central"><h3>Adresses IP autorisées</h3><p>Chaque client a sa liste. Toute autre adresse reçoit <code>403</code>, même avec un bon jeton ; une liste vide refuse tout le monde.</p></div>
        <div class="card b-central"><h3>Champs listés un par un</h3><p>Les réponses ne contiennent que des champs choisis : une nouvelle colonne ou les notes de l’admin ne sortent pas par accident.</p></div>
    </div>
    <p>
        Les jetons des agents n’ont pas cette capacité : un serveur ne peut pas lire le reste de la flotte. Limite :
        120 requêtes par minute et par adresse (au-delà, <code>429</code> avec <code>Retry-After</code>).
    </p>

    <h2 id="acces">Obtenir un accès</h2>
    <ol class="steps">
        <li><strong>Créer le client</strong> Un admin, dans <em>Réglages → Clients API</em>, crée un client avec l’adresse IP du serveur qui appellera l’API.</li>
        <li><strong>Copier le jeton</strong> Il n’est affiché qu’une fois. Le ranger dans un coffre ou dans les secrets de l’application.</li>
        <li><strong>Appeler depuis un serveur</strong> Jamais depuis un navigateur : le jeton y serait exposé, et l’adresse vue serait celle du visiteur.</li>
    </ol>

    <h2 id="routes">Les routes</h2>
    <p>Toutes en <code>GET</code>, avec l’en-tête <code>Authorization: Bearer &lt;jeton&gt;</code>. Les réponses sont en JSON, sous une clé <code>data</code> (sauf l’inventaire Ansible, au format d’Ansible).</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Route</th><th scope="col">Rôle</th></tr></thead>
            <tbody>
                @foreach ($routes as [$path, $text])
                    <tr><td><code>{{ $path }}</code></td><td>{{ $text }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 id="exemples">Exemples</h2>
<pre><code>export STACKCARD_TOKEN='&lt;jeton&gt;'   # apostrophes : le jeton contient un « | »
H="Authorization: Bearer $STACKCARD_TOKEN"

# la flotte
curl -fsS -H "$H" https://central.example.com/api/v1/servers

# un serveur, par son slug
curl -fsS -H "$H" https://central.example.com/api/v1/servers/web01

# la section packages de ce serveur
curl -fsS -H "$H" https://central.example.com/api/v1/servers/web01/sections/packages

# les mises à jour de sécurité de toute la flotte
curl -fsS -H "$H" 'https://central.example.com/api/v1/updates?security=1'

# qui a openssl, et en quelle version (la requête type du suivi des CVE)
curl -fsS -H "$H" 'https://central.example.com/api/v1/packages?name=openssl'

# les domaines servis par web01
curl -fsS -H "$H" 'https://central.example.com/api/v1/domains?server=web01'</code></pre>
    <p>Codes d’erreur : <code>401</code> jeton absent ou invalide, <code>403</code> adresse non autorisée, <code>404</code> serveur inconnu, <code>422</code> paramètre invalide, <code>429</code> trop de requêtes.</p>

    <h2 id="lire">Bien lire les réponses</h2>
    <ul>
        <li><strong><code>null</code> veut dire « non lu », jamais « absent ».</strong> Une liste vide veut dire « lu, rien trouvé ». Regarder <code>error</code> et <code>source_errors</code> avant de conclure.</li>
        <li><strong>Vérifier la fraîcheur</strong> : <code>stale</code> signale un serveur muet depuis plus de 3 h ; <code>updates_state</code> vaut <code>ok</code>, <code>stale</code> ou <code>unknown</code>. Seul <code>ok</code> permet de conclure qu’un paquet est à jour.</li>
        <li><strong>Comparer la version complète</strong> du paquet (par exemple <code>3.0.13-0ubuntu3.5</code>) à l’avis de sécurité, avec la logique de comparaison de dpkg : Ubuntu corrige souvent sans changer la version amont.</li>
        <li><strong>Une version corrigée ne suffit pas toujours</strong> : un service peut tourner sur l’ancienne bibliothèque (section <code>restart_needed</code>), et le noyau demande un redémarrage (<code>reboot_required</code>).</li>
        <li><strong>De nouveaux champs peuvent apparaître</strong> sans changement de version : ignorer ceux qu’on ne connaît pas.</li>
    </ul>

    <h2 id="documentation">Documentation complète</h2>
    <p>
        Le dépôt contient la description OpenAPI (<code>docs/api/stackcard-read-api.openapi.yaml</code>) et une collection
        Postman (<code>docs/api/stackcard-read-api.postman_collection.json</code>), avec chaque champ et des exemples de
        réponses. Ce sont elles qui font référence pour les développeurs d’une application cliente.
    </p>
@endsection

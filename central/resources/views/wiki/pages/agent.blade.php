@extends('wiki.layout')

@php
    $sections = [
        ['packages', 'paquets installés (dpkg) et leur paquet source'],
        ['apt_sources', 'dépôts APT actifs, origine des paquets clés, état d’Ubuntu Pro'],
        ['apt_updates', 'mises à jour APT en attente (dont sécurité) et paquets bloqués, par simulation'],
        ['kernel', 'noyaux en service et installés, redémarrage requis, livepatch'],
        ['restart_needed', 'services qui tournent encore sur d’anciennes bibliothèques (needrestart)'],
        ['kernel_surface', 'quelques valeurs sysctl, modules chargés, surcharges modprobe'],
        ['network', 'ports en écoute, nftables, iptables, Fail2Ban, UFW'],
        ['php', 'directives PHP effectives par version, pools PHP-FPM, mode PHP de chaque site'],
        ['apache', 'modules chargés, vhosts et quelques directives choisies'],
        ['site_dependencies', 'paquets Composer et applications connues, par dossier d’application'],
        ['node_apps', 'processus Node en écoute et dépendances résolues par leur fichier de verrouillage'],
        ['docker', 'version du démon, images et conteneurs (requêtes GET uniquement)'],
        ['persistence', 'crons, unités systemd hors paquet, comptes, empreintes des clés SSH, sudoers'],
        ['integrity', 'fichiers de paquets modifiés (debsums), chaque semaine'],
        ['code_patterns', 'motifs recherchés dans les DocumentRoot (chemin et ligne seulement), chaque semaine'],
    ];
@endphp

@section('content')
    <h2 id="role">Rôle</h2>
    <p>
        L’agent est un programme Python (bibliothèque standard uniquement) installé sur chaque serveur Ubuntu 22.04,
        24.04 ou 26.04 par le paquet <code>discover-agent</code>. Il tourne en root pour lire la configuration, <strong>sans
        rien modifier</strong>, et envoie un inventaire au central toutes les heures, en HTTPS. Il n’ouvre aucun port et
        n’accepte aucune commande.
    </p>

    <h2 id="installation">Installation et enrôlement</h2>
    <ol class="steps">
        <li><strong>Créer la fiche</strong> Dans l’administration : <em>Serveurs → Nouveau</em>, saisir un nom. Le slug est proposé à partir du nom.</li>
        <li><strong>Générer un jeton d’enrôlement</strong> Sur la fiche du serveur (réservé aux admins). La fenêtre affiche <strong>une seule fois</strong> la commande, l’adresse du central et le jeton, <strong>valable 24 h et à usage unique</strong>.</li>
        <li><strong>Installer sur le serveur</strong> Lancer le script d’installation (la commande exacte est sur la page « Commandes » de l’administration). Il télécharge le paquet, vérifie son empreinte SHA-256, l’installe, puis demande l’adresse du central et le jeton.</li>
        <li><strong>Premier envoi</strong> L’enrôlement écrit la configuration (root, 0600), active le timer systemd et envoie un premier inventaire. Le serveur passe « Actif ».</li>
    </ol>
<pre><code># commande donnée par la page « Commandes » de l’administration
curl -fsSL &lt;adresse du script install.sh&gt; | sudo sh

# sans questions (Ansible, plusieurs serveurs)
curl -fsSL &lt;adresse du script install.sh&gt; | sudo DISCOVER_URL=central.example.com DISCOVER_TOKEN=&lt;jeton&gt; sh

# agent déjà installé : enrôler ou réenrôler
sudo discover-agent enroll --url https://central.example.com --token &lt;jeton&gt;</code></pre>
    <div class="callout">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm1 12H9V9h2v5Zm0-7H9V5h2v2Z"/></svg>
        <p>
            Le script vérifie que le central répond en HTTPS avec un <strong>certificat valide</strong> avant d’aller plus
            loin. Le jeton est saisi sans écho et passé à l’agent par l’entrée standard, jamais en argument. Le serveur
            doit pouvoir joindre le central en HTTPS (port 443 sortant).
        </p>
    </div>
    <p>
        Relancer le script sur un serveur déjà installé met l’agent à jour si une version plus récente est publiée, puis
        propose de changer de jeton (répondre « non » garde la configuration). Le paquet existe aussi seul, pour une
        autorité de certification interne (<code>--ca-file</code>) ou l’épinglage de la clé du central (<code>--pin-sha256</code>).
    </p>

    <h2 id="cycle">Un passage horaire</h2>
    <p>
        Le timer systemd <code>discover-agent.timer</code> lance le service 2 minutes après le démarrage, puis une heure
        après chaque passage, avec jusqu’à 5 minutes de décalage aléatoire pour que les serveurs n’envoient pas tous en
        même temps. Le service tourne dans un bac à sable systemd : il n’écrit que dans son dossier d’état.
    </p>
    @include('wiki.diagrams.agent-cycle')
    <p>
        Chaque collecteur est isolé : s’il échoue, son erreur est ajoutée à l’inventaire et le reste est envoyé quand
        même. Le central répond seulement <code>{"status": "ok"}</code>.
    </p>

    <h2 id="sections">Les sections</h2>
    <p>
        En plus de l’inventaire de base (identité, IP, sites Apache, bases MySQL, Node, Docker, UFW), l’agent envoie
        <strong>15 sections d’état</strong>, affichées dans l’onglet « État détaillé » de la fiche serveur. Chaque section
        porte sa date de collecte, son empreinte et une éventuelle erreur : une liste vide veut dire « lu, rien trouvé »,
        une valeur absente veut dire « non lu ».
    </p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Section</th><th scope="col">Contenu</th></tr></thead>
            <tbody>
                @foreach ($sections as [$name, $text])
                    <tr><td><code>{{ $name }}</code></td><td>{{ $text }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <ul>
        <li><strong>Hebdomadaires</strong> : <code>integrity</code> et <code>code_patterns</code> sont lentes (jusqu’à 5 min). Elles tournent une fois par semaine (<code>weekly_interval_days</code>) ; un échec est retenté après 24 h. Elles ne partent pas avec l’enrôlement : le premier passage du timer les exécute.</li>
        <li><strong>Mises à jour APT</strong> : <code>apt_updates</code> (agent 0.6) est horaire. Le redémarrage requis vient de la section <code>kernel</code>.</li>
        <li><strong>Outils facultatifs</strong> : sans <code>debsums</code> ou <code>needrestart</code>, la section correspondante vaut <code>not_installed</code> ; sans <code>nftables</code>, la sous-source est signalée. L’agent n’installe rien lui-même.</li>
        <li><strong>Plafonds</strong> : une section de plus de 4 Mio, ou un ensemble de plus de 16 Mio, est remplacé par l’erreur <code>too_large</code>.</li>
    </ul>

    <h3>Les commandes APT de l’agent</h3>
    <p>Toutes avec des chemins absolus, la locale C et un environnement propre ; rien n’est jamais installé :</p>
    <ul>
        <li><code>apt-get update</code>, <strong>seulement si</strong> le cache APT et la dernière tentative de l’agent ont plus de <code>apt_refresh_hours</code> heures, et sans jamais attendre un verrou (si APT est occupé, l’agent passe son tour et lit le cache tel quel) ;</li>
        <li><code>apt-get -s dist-upgrade</code>, une simulation ;</li>
        <li><code>apt-mark showhold</code>, pour les paquets bloqués.</li>
    </ul>

    <h2 id="securite">Sécurité</h2>
    <div class="cards">
        <div class="card b-agent">
            <h3>Lecture seule</h3>
            <p>L’agent lit des fichiers et lance des commandes de consultation. Il n’exécute jamais un binaire qu’un utilisateur du serveur contrôle : seulement des chemins système.</p>
        </div>
        <div class="card b-agent">
            <h3>Masquage</h3>
            <p>Mots de passe, clés et jetons sont écartés dès la lecture ou remplacés par <code>***</code>. Le masquage reconnaît des formes connues : vérifiez avec <code>collect</code> avant d’enrôler un serveur sensible.</p>
        </div>
        <div class="card b-central">
            <h3>HTTPS vérifié</h3>
            <p>Les envois partent en HTTPS uniquement, et la vérification du certificat ne peut pas être désactivée. Épinglage de clé possible.</p>
        </div>
        <div class="card b-srv">
            <h3>Aucun port ouvert</h3>
            <p>L’agent n’écoute rien et n’accepte aucune commande : c’est toujours lui qui appelle le central.</p>
        </div>
    </div>
    <p>
        Quelques exemples de ce qui ne part jamais : les mots de passe des fichiers <code>.env</code> et
        <code>wp-config.php</code> (seuls le nom de la base, l’utilisateur et l’hôte sont lus), les variables
        d’environnement des conteneurs, les clés SSH (seulement type, empreinte et commentaire masqué), le contenu de
        <code>/etc/shadow</code>, les adresses bannies par Fail2Ban. Les fichiers de configuration et identifiants de
        l’agent sont écrits en 0600, et <code>status</code> n’affiche jamais le jeton.
    </p>

    <h2 id="reglages">Réglages (agent.conf)</h2>
    <p>
        Le fichier <code>/etc/discover-agent/agent.conf</code> est relu à chaque passage, sans redémarrage. Ce sont des
        réglages <strong>locaux</strong> : le central ne les pilote pas (on les change à la main ou avec Ansible).
    </p>
<pre><code>[collect]
# sections hebdomadaires (integrity, code_patterns) : tous les N jours, défaut 7
weekly_interval_days = 7
# apt-get update quand le cache a plus de N heures : défaut 6, minimum 1
apt_refresh_hours = 6</code></pre>
    <dl class="defs">
        <dt><code>weekly_interval_days</code></dt>
        <dd>Intervalle des sections hebdomadaires, en jours. Défaut 7 ; une valeur inférieure à 1 vaut 7.</dd>
        <dt><code>apt_refresh_hours</code></dt>
        <dd>Âge du cache APT, en heures, au-delà duquel l’agent lance <code>apt-get update</code>. Défaut 6 ; une valeur absente, non entière ou inférieure à 1 vaut 6. Pendant une crise : 1, puis retour à 6 (voir <a href="{{ route('wiki.page', 'procedures', false) }}#crise-cve">Crise CVE</a>).</dd>
    </dl>

    <h2 id="commandes">Commandes</h2>
<pre><code>sudo discover-agent status            # version, central, dernier envoi, dernière erreur
sudo discover-agent collect | less    # ce qui serait envoyé, sans l’envoyer
sudo discover-agent collect --full    # idem avec les sections hebdomadaires (lent)
sudo discover-agent run               # collecter et envoyer tout de suite
sudo systemctl start discover-agent   # un passage identique à celui du timer
systemctl list-timers discover-agent.timer
journalctl -u discover-agent.service -n 100 --no-pager</code></pre>
    <div class="callout warn">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 13H9v-2h2v2Zm0-4H9V7h2v4Z"/></svg>
        <p>
            <code>collect</code> n’envoie rien mais peut lancer <code>apt-get update</code> (si le cache est trop ancien).
            <code>run</code> lancé à la main ne passe pas par le bac à sable systemd : quelques données peuvent différer
            d’un passage du timer, c’est normal.
        </p>
    </div>
@endsection

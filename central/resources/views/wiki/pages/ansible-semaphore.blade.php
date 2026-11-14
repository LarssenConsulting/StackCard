@extends('wiki.layout')

@section('content')
    <h2 id="flux">Comment tout s’enchaîne</h2>
    <p>
        StackCard sait quels serveurs existent et dans quel état ils sont ; <strong>Semaphore UI</strong> lance les
        playbooks <strong>Ansible</strong> qui agissent sur eux. Le lien entre les deux est l’inventaire dynamique : à
        chaque exécution, Semaphore redemande la liste des serveurs à StackCard.
    </p>
    @include('wiki.diagrams.semaphore-flow')

    <h2 id="inventaire">L’inventaire dynamique</h2>
    <p>
        Le central expose <code>GET /api/v1/inventory/ansible</code>, protégé comme le reste de l’API de lecture. Le script
        <code>stackcard_inventory.py</code> (Python ≥ 3.10, bibliothèque standard, fourni dans <code>docs/ansible/</code>
        du dépôt) le transmet à Ansible. Il lit deux variables d’environnement :
    </p>
    <dl class="defs">
        <dt><code>STACKCARD_URL</code></dt><dd>La base du central, en HTTPS obligatoirement (le certificat est toujours vérifié). Option : <code>STACKCARD_CA_FILE</code> pour une autorité interne.</dd>
        <dt><code>STACKCARD_TOKEN</code></dt><dd>Le jeton du client API de Semaphore, à garder en secret.</dd>
    </dl>
    <h3>Hôtes et variables</h3>
    <p>
        Chaque hôte est nommé par son <strong>slug</strong> StackCard (<code>web01</code>), qui ne change pas quand on
        renomme le serveur. Variables d’hôte : <code>ansible_host</code> (l’adresse sûre), <code>ansible_port</code>
        (seulement si un port SSH est saisi dans StackCard), et des variables <code>stackcard_*</code> : nom, hostname,
        FQDN, système, <code>stackcard_stale</code>, date du dernier envoi, état et compteurs des mises à jour,
        redémarrage requis.
    </p>
    <h3>Groupes automatiques</h3>
    <p>Déduits des données, sans saisie ; un groupe vide n’est pas émis.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Groupe</th><th scope="col">Contient</th></tr></thead>
            <tbody>
                <tr><td><code>ubuntu_22_04</code>, <code>ubuntu_24_04</code>, <code>ubuntu_26_04</code></td><td>les serveurs de cette version d’Ubuntu</td></tr>
                <tr><td><code>apache</code>, <code>mysql</code>, <code>docker</code></td><td>les serveurs où le service est détecté</td></tr>
                <tr><td><code>stale</code></td><td>les serveurs muets depuis plus de 3 h (données et adresse peut-être périmées)</td></tr>
                <tr><td><code>updates_pending</code></td><td>au moins une mise à jour APT en attente</td></tr>
                <tr><td><code>security_updates</code></td><td>au moins une mise à jour de sécurité</td></tr>
                <tr><td><code>reboot_required</code></td><td>redémarrage requis</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        Les groupes de mises à jour reposent sur les <strong>dernières données connues</strong>, même périmées : mieux
        vaut viser un serveur de trop qu’en oublier un. Un serveur à l’état « inconnu » n’est ni dans
        <code>updates_pending</code> ni dans <code>security_updates</code>.
    </p>
<pre><code>export STACKCARD_URL=https://central.example.com
export STACKCARD_TOKEN='&lt;jeton&gt;'
./stackcard_inventory.py --list                  # le JSON de l’API
ansible -i stackcard_inventory.py ubuntu_24_04 -m ping
ansible -i stackcard_inventory.py all --limit web01 -m setup</code></pre>

    <h2 id="absent">Pourquoi un serveur est absent</h2>
    <p>L’inventaire ne contient que les serveurs <strong>actifs</strong> qui ont une <strong>adresse sûre</strong>. La colonne « Ansible » de la liste des serveurs dit pourquoi un serveur n’y est pas :</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Colonne Ansible</th><th scope="col">Raison</th><th scope="col">Que faire</th></tr></thead>
            <tbody>
                <tr><td><span class="badge ok">oui</span></td><td>dans l’inventaire</td><td>rien</td></tr>
                <tr><td><span class="badge">exclu</span></td><td>exclu par un admin</td><td>fiche → Modifier, décocher « Exclure de l’inventaire Ansible »</td></tr>
                <tr><td><span class="badge warn">IP à valider</span></td><td>l’agent signale une IP différente de l’IP validée</td><td>vérifier, puis « Valider la nouvelle IP »</td></tr>
                <tr><td><span class="badge danger">sans adresse</span></td><td>aucune adresse connue</td><td>saisir une adresse de connexion</td></tr>
                <tr><td><span class="badge">non</span></td><td>serveur en attente d’enrôlement ou révoqué</td><td>enrôler ou réenrôler l’agent</td></tr>
            </tbody>
        </table>
    </div>

    <h2 id="semaphore">Configurer Semaphore</h2>
    <ol class="steps">
        <li><strong>Client API</strong> Dans StackCard, <em>Réglages → Clients API</em> : créer un client avec l’adresse IP de Semaphore, copier le jeton. Si Semaphore passe par un reverse proxy, celui-ci doit être déclaré comme proxy de confiance du central.</li>
        <li><strong>Key Store</strong> Ajouter la clé SSH privée du compte <code>ansible</code>, et le jeton d’accès au dépôt de playbooks : un jeton à portée fine, en <strong>lecture seule</strong>, limité à ce dépôt.</li>
        <li><strong>Dépôt</strong> Déclarer le dépôt de playbooks par son URL HTTPS, avec ce jeton. Le script <code>stackcard_inventory.py</code> est à sa racine, exécutable.</li>
        <li><strong>Variable Group</strong> (« Environment » dans les versions plus anciennes) : <code>STACKCARD_URL</code> en variable d’environnement, <code>STACKCARD_TOKEN</code> dans les secrets.</li>
        <li><strong>Inventory</strong> Type <em>File</em>, chemin <code>stackcard_inventory.py</code>, dans le dépôt de playbooks, avec la clé SSH du Key Store.</li>
        <li><strong>Templates</strong> Un modèle de tâche par playbook, avec cet inventaire et ce Variable Group. Le champ <em>Limit</em> restreint une exécution à un hôte (<code>web01</code>) ou un groupe (<code>security_updates</code>).</li>
    </ol>
<pre><code>{
  "STACKCARD_URL": "https://central.example.com",
  "STACKCARD_TOKEN": "&lt;jeton&gt;"
}</code></pre>

    <h2 id="compte">Le compte ansible</h2>
    <p>
        Sur chaque serveur, un compte <code>ansible</code> dédié : sa clé publique dans <code>authorized_keys</code>, sa clé
        privée dans le Key Store de Semaphore, et <code>sudo</code> sans mot de passe (les playbooks utilisent
        <code>become</code>). Le port SSH vient de StackCard (<code>ansible_port</code>) quand il n’est pas le port par défaut.
    </p>

    <h2 id="cles">Clés d’hôte SSH</h2>
    <div class="callout warn">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 13H9v-2h2v2Zm0-4H9V7h2v4Z"/></svg>
        <p>
            <strong>Ne désactivez pas la vérification des clés d’hôte</strong> (<code>host_key_checking = True</code> dans
            <code>ansible.cfg</code>). C’est la seconde barrière si une adresse pointe vers la mauvaise machine.
            Enregistrez les clés de vos serveurs dans le <code>known_hosts</code> de Semaphore, après avoir vérifié leur empreinte.
        </p>
    </div>
<pre><code>ssh-keyscan 192.0.2.10 &gt;&gt; ~/.ssh/known_hosts   # puis comparer l’empreinte avec celle du serveur</code></pre>

    <h2 id="playbooks">Les playbooks fournis</h2>
    <p>Le dépôt de playbooks contient, à côté du script d’inventaire :</p>
    <div class="cards">
        <div class="card b-sema">
            <h3><code>ping.yml</code></h3>
            <p>Test de connexion : chaque serveur de l’inventaire doit répondre « pong » (SSH et Python).</p>
        </div>
        <div class="card b-sema">
            <h3><code>stackcard-agent.yml</code></h3>
            <p>Installe ou met à jour l’agent (dernière version publiée, vérifiée par SHA-256) ; l’enrôle si <code>discover_token</code> est fourni. <code>send_now: true</code> envoie un inventaire tout de suite.</p>
        </div>
        <div class="card b-sema">
            <h3><code>stackcard-apt-refresh.yml</code></h3>
            <p>Règle <code>apt_refresh_hours</code> dans <code>agent.conf</code> (entier de 1 à 8760, défaut 6). Ne touche pas un serveur sans agent.</p>
        </div>
        <div class="card b-sema">
            <h3><code>apt-upgrade.yml</code></h3>
            <p><code>apt-get update</code> puis <code>dist-upgrade -y</code>, configurations locales gardées, paquets bloqués respectés, puis un inventaire StackCard.</p>
        </div>
    </div>
    <h3>Variables de <code>apt-upgrade.yml</code></h3>
    <dl class="defs">
        <dt><code>reboot_if_required</code></dt><dd>défaut <code>false</code> : le redémarrage est seulement signalé ; <code>true</code> redémarre les serveurs qui le demandent.</dd>
        <dt><code>autoremove</code></dt><dd>défaut <code>false</code> : supprimer les paquets devenus inutiles.</dd>
        <dt><code>refresh_stackcard</code></dt><dd>défaut <code>true</code> : lancer <code>discover-agent run</code> à la fin, pour que StackCard montre tout de suite l’état à jour.</dd>
        <dt><code>batch_size</code></dt><dd>nombre de serveurs traités à la fois (défaut : tous).</dd>
    </dl>
    <p>
        Si APT est occupé (mises à jour automatiques, rafraîchissement du cache par l’agent), le playbook attend le verrou
        jusqu’à 10 minutes au lieu d’échouer. Sauf <code>ping.yml</code>, les playbooks acceptent aussi <code>target_hosts</code> (défaut
        <code>all</code>), en plus du champ <em>Limit</em> de Semaphore.
    </p>
    <div class="callout">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm1 12H9V9h2v5Zm0-7H9V5h2v2Z"/></svg>
        <p>Aucun secret dans le dépôt de playbooks : le jeton de StackCard, les jetons d’enrôlement et les clés SSH restent dans Semaphore.</p>
    </div>
@endsection

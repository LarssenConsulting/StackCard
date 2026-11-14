@extends('wiki.layout')

@section('content')
    <h2 id="cycle">Le cycle des mises à jour</h2>
    <p>
        Les procédures ci-dessous suivent toutes la même logique : l’agent observe, StackCard montre, Ansible agit, puis
        l’agent confirme le nouvel état.
    </p>
    @include('wiki.diagrams.updates-cycle')

    <h2 id="nouveau-serveur">Ajouter un nouveau serveur</h2>
    <ol class="steps">
        <li><strong>Créer la fiche</strong> <em>Serveurs → Nouveau</em>, saisir un nom. Relire le slug proposé : il ne sera plus modifiable après le premier inventaire.</li>
        <li><strong>Générer un jeton d’enrôlement</strong> Sur la fiche (admins). Il vaut 24 h et ne sert qu’une fois : un jeton par serveur.</li>
        <li><strong>Installer l’agent</strong> À la main, avec la commande de la page « Commandes ». Ou par Ansible : le serveur n’étant pas encore dans l’inventaire StackCard, utiliser un inventaire statique de Semaphore qui le contient (compte <code>ansible</code> déjà créé), et lancer <code>stackcard-agent.yml</code> avec <code>discover_token</code> et <code>send_now: true</code>.</li>
        <li><strong>Vérifier la fiche</strong> État « Actif », slug figé, adresse renseignée. Derrière un NAT ou un VPN, saisir l’adresse de connexion et le port SSH (fiche → Modifier).</li>
        <li><strong>Enregistrer la clé d’hôte</strong> Dans le <code>known_hosts</code> de Semaphore, après vérification de l’empreinte.</li>
        <li><strong>Tester</strong> Lancer <code>ping.yml</code> avec <em>Limit</em> = le slug du serveur (par exemple <code>web03</code>). La colonne « Ansible » doit afficher « oui ».</li>
    </ol>

    <h2 id="maj-agent">Mettre à jour l’agent sur la flotte</h2>
    <ol class="steps">
        <li><strong>Repérer</strong> Dans la liste des serveurs, une version d’agent en orange signale qu’une version plus récente est publiée.</li>
        <li><strong>Lancer <code>stackcard-agent.yml</code></strong> Sans variable : il télécharge la dernière version, vérifie son empreinte et l’installe ; rien n’est fait là où la version est déjà la bonne. La configuration et l’enrôlement sont conservés.</li>
        <li><strong>Envoyer tout de suite</strong> (facultatif) Avec <code>send_now: true</code>, chaque serveur envoie un inventaire à la fin ; sinon la nouvelle version apparaît au passage horaire suivant.</li>
        <li><strong>Contrôler</strong> Le résumé du playbook donne, par serveur, la version avant → après. La colonne « Agent » ne doit plus être orange.</li>
    </ol>
    <p>Sur un serveur isolé, relancer le script d’installation revient au même (répondre « non » à « Mettre à jour le jeton ? »).</p>

    <h2 id="maj-serveurs">Mettre les serveurs à jour</h2>
    <ol class="steps">
        <li><strong>Voir ce qui attend</strong> Liste des serveurs, filtre « Mises à jour de sécurité » ; ou la page « Mises à jour » pour la vue par paquet. Un badge rouge « N dont M sécu » demande une action.</li>
        <li><strong>Choisir la cible</strong> Le groupe <code>security_updates</code> pour une campagne de sécurité, <code>updates_pending</code> pour tout mettre à jour, ou un slug pour un seul serveur.</li>
        <li><strong>Lancer <code>apt-upgrade.yml</code></strong> Avec <em>Limit</em> = la cible. Sur une grosse flotte, <code>batch_size</code> traite les serveurs par lots ; si tous les serveurs d’un lot échouent, Ansible s’arrête avant les lots suivants.</li>
        <li><strong>Redémarrer si besoin</strong> Le résumé indique « redémarrage REQUIS ». Relancer avec <code>reboot_if_required: true</code> (par exemple sur le groupe <code>reboot_required</code>) au moment choisi.</li>
        <li><strong>Vérifier</strong> En fin de playbook, l’agent envoie un inventaire : les badges passent à « à jour » (vert) dans StackCard.</li>
    </ol>
    <div class="callout">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm1 12H9V9h2v5Zm0-7H9V5h2v2Z"/></svg>
        <p><code>apt-upgrade.yml</code> applique <strong>toutes</strong> les mises à jour en attente des serveurs visés (<code>dist-upgrade</code>), pas seulement celles de sécurité. Les paquets bloqués (<code>apt-mark hold</code>) ne bougent pas.</p>
    </div>

    <h2 id="crise-cve">Crise CVE</h2>
    <p>Une faille est publiée et un correctif arrive dans les dépôts Ubuntu : il faut savoir vite qui est concerné, puis corriger.</p>
    <ol class="steps">
        <li><strong>Accélérer la détection</strong> Lancer <code>stackcard-apt-refresh.yml</code> avec <code>apt_refresh_hours: 1</code>. Les agents relisent le réglage à leur passage suivant, dans l’heure, et rafraîchissent leur cache APT chaque heure.</li>
        <li><strong>Mesurer l’exposition</strong> L’outil de suivi des CVE interroge <code>/api/v1/packages?name=&lt;paquet&gt;</code> : version installée et version proposée, serveur par serveur, avec l’état de fraîcheur. Dans l’administration, la page « Mises à jour » donne la même vue.</li>
        <li><strong>Corriger</strong> Lancer <code>apt-upgrade.yml</code> sur <code>security_updates</code>, ou sur les seuls serveurs concernés via <em>Limit</em>, puis redémarrer ceux qui le demandent.</li>
        <li><strong>Confirmer</strong> Les serveurs corrigés n’ont plus de mise à jour pour ce paquet, à l’état « ok ». Un serveur « périmé » ou « inconnu » ne prouve rien : le vérifier.</li>
        <li><strong>Revenir à la normale</strong> Relancer <code>stackcard-apt-refresh.yml</code> avec <code>apt_refresh_hours: 6</code>.</li>
    </ol>

    <h2 id="reinstaller">Réinstaller un serveur</h2>
    <div class="callout warn">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 13H9v-2h2v2Zm0-4H9V7h2v4Z"/></svg>
        <p><strong>Ne créez pas un nouveau serveur.</strong> Le slug est l’identifiant des outils externes (Ansible, suivi des CVE) : une nouvelle fiche le perdrait.</p>
    </div>
    <ol class="steps">
        <li><strong>Régénérer un jeton</strong> Sur la fiche existante (« Générer un jeton d’enrôlement »).</li>
        <li><strong>Réinstaller l’agent</strong> Sur la machine refaite, avec le script d’installation, ou <code>sudo discover-agent enroll --url https://central.example.com --token &lt;jeton&gt;</code> si le paquet est déjà là.</li>
        <li><strong>Vérifier</strong> La fiche est de nouveau « Actif », avec le même slug ; le <code>machine-id</code> de la nouvelle installation remplace l’ancien.</li>
        <li><strong>Mettre à jour la clé d’hôte</strong> Une machine réinstallée a une nouvelle clé SSH : remplacer l’ancienne entrée du <code>known_hosts</code> de Semaphore, après vérification.</li>
    </ol>

    <h2 id="ip-changee">Une IP a changé</h2>
    <ol class="steps">
        <li><strong>Constater</strong> Colonne « Adresse » en orange (« IP changée, à valider »), colonne « Ansible » à « IP à valider » : le serveur est sorti de l’inventaire.</li>
        <li><strong>Vérifier que le changement est attendu</strong> Migration, changement chez l’hébergeur… Un changement inexpliqué mérite une enquête avant tout.</li>
        <li><strong>Valider</strong> Cliquer « Valider la nouvelle IP » (liste ou fiche, admins). Le serveur revient dans l’inventaire au prochain lancement de Semaphore.</li>
        <li><strong>Mettre à jour <code>known_hosts</code></strong> Ajouter la clé d’hôte sous la nouvelle adresse, après vérification de l’empreinte.</li>
    </ol>
    <p>Si l’adresse vue par la machine n’est pas celle par laquelle on la joint (NAT, VPN), saisir plutôt une adresse de connexion : elle prime sur l’IP remontée par l’agent.</p>

    <h2 id="exclure">Exclure un serveur d’Ansible</h2>
    <ol class="steps">
        <li><strong>Ouvrir la fiche</strong> puis <em>Modifier</em> (admins).</li>
        <li><strong>Cocher</strong> « Exclure de l’inventaire Ansible », enregistrer. La colonne « Ansible » affiche « exclu ».</li>
        <li><strong>Pour le réintégrer</strong> décocher la même case.</li>
    </ol>
    <p>Le serveur reste visible partout ailleurs, dans l’administration comme dans l’API : seul l’inventaire Ansible l’ignore.</p>
@endsection

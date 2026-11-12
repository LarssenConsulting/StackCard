# StackCard, source de vérité des serveurs et des domaines

Date : 2026-11-12
Statut : issu de la conversation, relu par un builder, en attente de validation de l'utilisateur.

## Intention

L'utilisateur gère des serveurs pour son home lab et pour ses clients (dont il est propriétaire : il installe ce qu'il veut). Il a régulièrement besoin de la liste de ces serveurs, et des domaines qu'ils servent, dans d'autres outils. Aujourd'hui chaque outil tient sa propre liste ou son propre « mapping », dont il n'est pas content.

StackCard reçoit déjà, automatiquement, l'état de chaque serveur où tourne l'agent. Il devient **la** référence : un serveur enrôlé apparaît, un serveur retiré disparaît, sans liste parallèle à maintenir.

Consommateurs connus :
1. **Ansible via Semaphore UI** : premier usage, inventaire à jour à chaque exécution.
2. **Le système de suivi des CVE** de l'utilisateur, qui crée une ligne Todoist par serveur : il doit rapprocher ses lignes des serveurs par l'identifiant StackCard, qui devient la règle.
3. **L'utilisateur lui-même** : « savoir ce qui est installé et où ».
4. D'autres, inconnus : les sorties doivent être génériques.

## Objectifs

- Un identifiant de serveur stable et lisible (**slug**), qui ne change jamais, et que tous les outils utilisent.
- L'API de lecture renvoie, pour chaque serveur, son slug et son nom réel.
- Une liste des domaines de toute la flotte, avec où chacun est servi, par l'API et dans l'admin.
- Un inventaire Ansible prêt à l'emploi.

## Non-objectifs

- Classer les serveurs (client, home lab, prod/test, rôle) : pas maintenant.
- Les domaines déclarés dans Traefik : plus tard (le modèle de domaines ne doit pas l'empêcher, voir §5).
- NAS, routeurs, ou toute machine sans agent : hors périmètre. Le périmètre = les serveurs enrôlés.
- Les domaines achetés chez un registrar et non servis par un serveur.
- Toute écriture par l'API : elle reste en lecture seule ; l'agent ne reçoit toujours aucune instruction.

## Anti-objectifs (échec même si « ça marche »)

- Un slug qui change (renommage, réinstallation, changement de hostname) : casse les rapprochements Todoist et l'historique CVE.
- Affaiblir les règles de l'API de lecture : jeton `ApiClient` avec `inventory:read`, `allowed_ips` (liste vide = personne), champs listés un par un (les notes de l'admin ne sortent pas), jetons d'agent sans cette capacité.

## Contraintes

- Règles du `CLAUDE.md` : migration + fichier SQL dans `_release/`, `docs/api/` (OpenAPI + Postman) suivi à chaque changement de route ou de champ, textes d'interface en français, tests Pest.
- Ce projet ne touche que le central. Aucune modification de l'agent ni du contrat de snapshot.

## Décisions

### 1. Slug de serveur

- Nouvelle colonne `servers.slug`, unique, non nulle.
- Format : minuscules, chiffres et tirets (`[a-z0-9]+(-[a-z0-9]+)*`), 64 caractères max, **jamais uniquement des chiffres** (pour ne pas être confondu avec l'identifiant numérique dans les URL). Il sert aussi de nom d'hôte Ansible.
- Proposé automatiquement à partir du nom saisi à la création du serveur, modifiable dans le formulaire **tant que le serveur n'a jamais envoyé d'inventaire**, puis **figé** pour toujours (voir `slug_locked_at` ci-dessous). *(choix de Claude, présenté comme supposition, sans objection)*
- Collision à la création : refus de validation avec message clair, pas de suffixe silencieux. *(choix de Claude)*
- Serveurs existants : la migration calcule un slug une fois à partir de leur `name` actuel, en suffixant `-2`, `-3`… en cas de collision (et en préfixant `srv-` si le résultat n'est fait que de chiffres ou est vide). **Ces slugs ne sont pas figés par la migration** (décision de l'utilisateur) : l'admin les relit et les corrige dans l'admin, puis les fige d'un clic (action admin « Figer les slugs », avec confirmation, qui fige tous les slugs encore modifiables des serveurs ayant déjà envoyé un inventaire). Concrètement, un indicateur `slug_locked_at` (nullable) porte l'état : posé au premier inventaire pour un nouveau serveur, posé par l'action pour les serveurs existants ; un slug n'est modifiable que tant qu'il est nul. Les consommateurs (CVE/Todoist) ne s'y branchent qu'après ce clic. Tant qu'il reste des slugs non figés sur des serveurs actifs, l'admin le signale (bandeau ou indicateur sur la liste des serveurs).
- Un serveur révoqué garde son slug ; un serveur supprimé le libère. *(choix de Claude)*
- Réinstallation : déjà gérée par le code actuel. On génère un nouveau jeton d'enrôlement sur la fiche existante ; l'agent réinstallé s'enrôle dessus, `machine_id` est remplacé, la fiche (donc le slug) est conservée. Rien à construire, mais `docs/install-agent.md` et la page « Commandes » doivent le dire explicitement : « pour une réinstallation, régénérer un jeton sur la fiche existante, ne pas créer un nouveau serveur ». Un serveur révoqué se réenrôle de la même façon : `CreateEnrollmentToken` le remet déjà en attente. Rien à construire.
- Le slug est affiché dans la liste et la fiche serveur de l'admin, copiable.

### 2. Nom réel

L'API renvoie à côté du slug : `name` (nom donné dans l'admin), `hostname` et `fqdn` (remontés par l'agent). Les trois existent déjà ; on ajoute `slug`.

### 3. Adresse de connexion

- Nouvelle colonne `servers.connect_address` (nullable), saisie à la main dans l'admin (IP ou nom d'hôte, validé comme tel). Elle sert quand l'IP vue par la machine n'est pas joignable (NAT, VPN, réseau privé).
- Nouvelle colonne `servers.connect_port` (nullable, 1–65535) pour une redirection de port derrière un NAT ; émise en `ansible_port` seulement si renseignée, sinon Ansible garde son défaut. *(choix de Claude)*
- `slug`, `connect_address` et `connect_port` ne sont modifiables que par les admins (le rôle `user` les voit, sans pouvoir les changer) : `connect_address` décide où Semaphore envoie ses identifiants SSH. Tests à l'appui. *(choix de Claude, dans la ligne de la règle « écran sensible = admins »)*
- Adresse effective = `connect_address` si renseignée, sinon `primary_ip` remontée par l'agent.
- L'API serveurs expose `primary_ip` et `connect_address` ; l'inventaire Ansible met l'adresse effective dans `ansible_host`.

### 4. API serveurs

- `GET /api/v1/servers` et `GET /api/v1/servers/{server}` : ajout de `slug`, `connect_address` et `connect_port`.
- `{server}` accepte l'identifiant numérique (compatibilité) **ou** le slug, y compris pour `/servers/{server}/sections/{section}`.

### 5. Domaines

Un « domaine » = chaque `ServerName` et chaque alias des sites Apache remontés par l'agent, normalisé en minuscules. Les jokers (`*.exemple.fr`) sont gardés tels quels.

- **API** `GET /api/v1/domains` : une entrée par domaine, triée par nom. Chaque entrée porte la liste de ses emplacements (`locations`), un par site qui le sert :
  - `source` : `"apache"` (prépare l'arrivée de Traefik sans casser les consommateurs) ;
  - `server` : `{slug, name}` et `server_stale` (même règle que `stale` des serveurs) ;
  - `role` : `server_name` ou `alias` ;
  - champs du site : `port`, `https`, `cert_expires_at`, `type`, `document_root`, `app_dir`, `upstream`, `php_version`, `technologies`.
- Filtre facultatif `?server=<slug>`. Pas de pagination tant que la flotte est petite. *(choix de Claude)*
- Un même domaine sur plusieurs serveurs ou vhosts (80 qui redirige + 443) = une seule entrée, plusieurs emplacements.
- Champs listés un par un, comme le reste de l'API. Serveurs révoqués exclus. *(choix de Claude)*
- **Admin** : page « Domaines » en lecture seule, accessible à tous les rôles comme Sites et Bases : un domaine par ligne, recherche, colonnes serveur(s), HTTPS, expiration du certificat, type, technologies ; lien vers le site ou le serveur.
- Stockage laissé au builder. Les alias sont en JSON dans `sites.aliases` ; une table dérivée remplie par `IngestSnapshot` (l'état d'un serveur y est déjà remplacé en bloc à chaque envoi) est la piste recommandée pour la recherche et le tri. Si table : migration + `_release/`.

### 6. Inventaire Ansible

- `GET /api/v1/inventory/ansible` : JSON au format d'inventaire dynamique d'Ansible (`_meta.hostvars` + groupes), protégé exactement comme le reste de l'API de lecture.
- Hôtes = serveurs **actifs** (ni révoqués, ni en attente d'enrôlement), nommés par leur **slug**. Les serveurs « stale » restent inclus et sont signalés. *(choix de Claude : un serveur muet depuis 3 h reste en général joignable)*
- `hostvars` : `ansible_host` (adresse effective), `stackcard_id`, `stackcard_name`, `stackcard_hostname`, `stackcard_fqdn`, `stackcard_os`, `stackcard_stale`, `stackcard_last_seen_at`. Pas d'`ansible_user` : laissé à la configuration Semaphore. Un serveur sans adresse effective est omis de l'inventaire (et listé nulle part ailleurs comme erreur : l'admin le voit sur sa fiche).
- Groupes automatiques, déduits des données, sans saisie : `ubuntu_22_04` / `ubuntu_24_04` / `ubuntu_26_04` (depuis `os`), `apache`, `mysql`, `docker` (version non nulle), `stale`. Tous les hôtes sont dans `all`. *(choix de Claude)*
- Script d'inventaire fourni (stdlib Python uniquement, par ex. `docs/ansible/stackcard_inventory.py`) : URL et jeton lus dans `STACKCARD_URL` et `STACKCARD_TOKEN`, répond à `--list` et `--host <slug>`, affiche le JSON. Doc courte en français (`docs/ansible.md`) : création du client API dans l'admin (IP de Semaphore), variables à déclarer dans Semaphore, utilisation du script comme inventaire.

### 7. Correctif inclus

Une requête sans jeton sur `/api/*` plante (`Route [login] not defined`, 500) au lieu de répondre 401. Corriger dans `bootstrap/app.php` (`redirectGuestsTo` : aucune redirection pour `api/*`, page de connexion Filament sinon), avec un test.

## Laissé au builder

- Le stockage des domaines (§5).
- La présentation exacte de la page « Domaines » et l'emplacement du slug dans l'admin.
- Le nom et l'emplacement exacts du script Ansible et de sa doc.
- Le découpage en commits.

## Documentation à tenir à jour

`docs/api/` (OpenAPI + Postman : nouveaux champs, `/domains`, `/inventory/ansible`, `{server}` par slug), `docs/install-agent.md` et page « Commandes » (réinstallation), `CLAUDE.md` (slug figé = règle, nouvelles routes), nouvelle doc Ansible.

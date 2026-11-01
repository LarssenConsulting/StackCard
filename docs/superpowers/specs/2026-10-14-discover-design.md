# Discover — Design (v1)

Date : 2026-10-14
Statut : validé en conversation (parties 1 à 3), relecture écrite déléguée par l'utilisateur.

## 1. Objectif

Centraliser en un seul endroit les informations serveurs **et** applicatives d'un parc d'environ 10 serveurs (majoritairement des VM) sous Ubuntu (22.04, 24.04 et 26.04) :

- nom et IP du serveur ;
- sites web servis par Apache (sites PHP et sites JavaScript en reverse proxy) ;
- bases MySQL/MariaDB ;
- utilisateurs liés aux sites : utilisateur **système** (propriétaire, pool PHP-FPM, processus Node) et utilisateur **base de données** (lu dans la config applicative).

## 2. Architecture

```
[Serveur Ubuntu]                               [Central]
discover-agent (Python stdlib, root)  --HTTPS-->  Laravel + Filament + MySQL
  timer systemd (1 h)                 POST /api/v1/inventory
  collecteurs -> snapshot JSON        Bearer <jeton serveur>
```

- Mode **push** uniquement. L'agent n'écoute sur aucun port. Le central ne peut envoyer aucune commande à l'agent.
- Dépôt unique : `agent/` (Python), `central/` (Laravel), `docs/`.

## 3. Sécurité

| Sujet | Décision |
|---|---|
| Transport | HTTPS obligatoire, TLS ≥ 1.2, vérification du certificat toujours active (aucune option pour la couper). `ca_file` optionnel pour une autorité de certification interne. |
| Épinglage | Optionnel : `pin_sha256` = SHA-256 (base64) de la clé publique (SPKI) du certificat serveur. Une clé différente fait échouer l'envoi. |
| Enrôlement | Jeton d'enrôlement à usage unique, valable 24 h, généré dans l'interface. L'agent l'échange contre un jeton permanent propre au serveur. |
| Stockage côté agent | `/etc/discover-agent/agent.conf` et `/etc/discover-agent/credentials` : root, 0600. |
| Stockage côté central | Seule l'empreinte des jetons est stockée (Sanctum pour le jeton agent, SHA-256 pour le jeton d'enrôlement). Révocation et renouvellement depuis l'interface. |
| Secrets | L'agent ne transmet jamais de mot de passe ni de clé. Les clés de config contenant `PASS`, `SECRET`, `KEY`, `TOKEN` ou `SALT` sont ignorées à la lecture. Le mot de passe est retiré des `DATABASE_URL`. Les empreintes de mot de passe MySQL ne sont jamais lues. |
| API | Validation stricte du JSON, taille maximale 20 Mo (agent 0.5, sections détaillées ; 5 Mo avant), 30 requêtes/minute par jeton. Journal de chaque réception (serveur, date, IP source). |
| Interface | Comptes administrateur avec double authentification (MFA Filament), rôle unique en v1. |
| Restriction IP | Optionnelle, au niveau d'Apache (documentation). |

## 4. Agent

### 4.1 Commandes

| Commande | Rôle |
|---|---|
| `discover-agent enroll --url URL --token TOKEN [--ca-file F] [--pin-sha256 P]` | Enrôle le serveur, écrit la config et les identifiants, active le timer et envoie un premier inventaire sans les sections hebdomadaires (le timer les exécute). |
| `discover-agent collect [--full]` | Affiche le snapshot JSON sur stdout sans l'envoyer. `--full` exécute aussi les sections hebdomadaires (`integrity`, `code_patterns`) ; sans lui elles valent `skipped_not_due`. Ne modifie jamais `state.json`. |
| `discover-agent run` | Collecte et envoie (lancé par le timer). |
| `discover-agent status` | Affiche la config (sans le jeton), le dernier envoi réussi et les dernières erreurs. |

État local : `/var/lib/discover-agent/state.json` (date du dernier envoi, dernier code HTTP, erreurs).

### 4.2 Planification

`discover-agent.timer` : `OnBootSec=2min`, `OnUnitActiveSec=1h`, `RandomizedDelaySec=5min`. Le service est de type `oneshot`.

### 4.3 Robustesse

- Chaque collecteur est indépendant. Une exception est ajoutée à `errors[]` sous la forme `{collector, message}`, et l'envoi a quand même lieu.
- Chaque sous-processus a un délai maximum de 15 s.
- Un échec d'envoi est enregistré dans l'état local. L'exécution suivante renvoie un snapshot complet ; il n'y a pas de file d'attente.

### 4.4 Collecteurs

**Serveur** : `hostname`, `fqdn`, `machine_id` (`/etc/machine-id`), `primary_ip` (source de `ip -j route get 1.1.1.1`), interfaces et IP (`ip -j addr`, sans `lo`), `os` (`/etc/os-release` PRETTY_NAME), `kernel`, `virtualization` (`systemd-detect-virt`), `agent_version`.

**Apache** :
- Vhosts actifs via `apache2ctl -S` (fichier:ligne de chaque vhost).
- Lecture des blocs `<VirtualHost>` dans chaque fichier : `ServerName`, `ServerAlias`, port, `SSLEngine`, `SSLCertificateFile` (date d'expiration via `openssl x509 -enddate -noout`), `DocumentRoot`, `ProxyPass` (cible de `/`), `SetHandler` FPM (`proxy:unix:<socket>|fcgi://…`), `AssignUserID` (mpm-itk).
- `partial: true` si la config contient une variable `${…}` non résolue ou une macro `Use`.
- Version d'Apache via `apache2 -v`.

**Classement des sites** :

| type | Détection | Utilisateur système | Dossier applicatif |
|---|---|---|---|
| `php-fpm` | `SetHandler proxy:unix:…sock` | `user`/`group` du pool dont `listen` = socket (`/etc/php/*/fpm/pool.d/*.conf`), et version de PHP | DocumentRoot |
| `php-mod` | module `php*` chargé et DocumentRoot présent | `AssignUserID` sinon `www-data` | DocumentRoot |
| `node` | `ProxyPass / http://(127.0.0.1\|localhost\|[::1]):PORT` | utilisateur du processus qui écoute sur PORT (`ss -Hltnp` puis `/proc/<pid>/status`) | `/proc/<pid>/cwd` |
| `proxy` | `ProxyPass` vers un autre hôte | — | — (`upstream` renseigné) |
| `static` | aucun des cas ci-dessus | propriétaire du DocumentRoot | DocumentRoot |

Pour chaque site : `owner_user` et `owner_group` du dossier applicatif. Pour `node`, `process_manager` vaut `systemd:<unit>` (lu dans `/proc/<pid>/cgroup`), `pm2` (si un ancêtre du processus s'appelle `PM2`) ou `unknown`.

**Références de base** (dossier applicatif, puis jusqu'à 2 dossiers parents ; on s'arrête au premier niveau qui contient un fichier reconnu) :
- `wp-config.php` : `DB_NAME`, `DB_USER`, `DB_HOST`, lus par regex sur `define(...)`, sans exécuter le PHP.
- `.env`, `.env.local`, `.env.production` : `DB_DATABASE`/`DB_NAME`, `DB_USERNAME`/`DB_USER`, `DB_HOST` (+ `DB_PORT`), ou `DATABASE_URL` (`mysql://user:***@host:port/db`).
- Format : `{db_name, db_user, db_host, source}` (`source` = chemin du fichier). Seules les références MySQL/MariaDB sont retenues.

**Technos** (ajout 0.2.0, même recherche : dossier applicatif puis jusqu'à 2 parents, arrêt au premier niveau qui contient `composer.json`, `package.json` ou un fichier de version connu). Rien n'est exécuté ; on ne garde qu'un nom de produit connu et une chaîne en forme de version :
- CMS par leur fichier de version : WordPress (`wp-includes/version.php`), Nextcloud (`version.php`), Drupal (`core/lib/Drupal.php`), PrestaShop (`app/AppKernel.php`), Joomla (`libraries/src/Version.php`) ;
- frameworks PHP par `composer.lock` (≤ 8 Mio ; à défaut `composer.json`, nom sans version) : Laravel, Filament, Livewire, Symfony, Drupal, Magento, CakePHP, CodeIgniter, Yii, Slim ;
- frameworks Node par les `dependencies` de `package.json`, version lue dans `node_modules/<paquet>/package.json` : Next.js, Nuxt, SvelteKit, Astro, Remix, NestJS, AdonisJS, Strapi, Express, Fastify, Koa, Hono.
- Format : `technologies: [{name, version|null}]` sur chaque site.

**Applications Node** (ajout 0.2.0) : tout processus dont l'exécutable (`/proc/<pid>/exe`) est `node`/`nodejs` et qui écoute un port TCP (`ss -Hltnp`), avec ou sans vhost devant. Un même processus (ou des workers qui partagent le socket) donne une seule application, regroupée par (utilisateur, dossier, gestionnaire). Pour chacune : `name`/`version` du `package.json` de son dossier, `node_version` (dossier nvm/n dans le chemin de l'exécutable, sinon `dpkg-query` du paquet `nodejs` ; jamais en lançant `node`), `listen` (`[{address, port}]`), `user`, `group`, `app_dir` (cwd), `process_manager`, `technologies`.

**Conteneurs Docker** (ajout 0.3.0, si `/var/run/docker.sock` est un socket) : l'API Engine est lue par ce socket Unix, en `GET` uniquement (`/version`, `/containers/json?all=1`, puis `/containers/<id>/json` pour chaque conteneur dont l'id est hexadécimal). Pour chaque conteneur, arrêté ou non : `id` (12 caractères), `name`, `image`, `image_version` (label `org.opencontainers.image.version`), `state`, `ports` (ports publiés sur l'hôte : `[{address, port, container_port, protocol}]`), `compose_project`, `compose_service`, `compose_dir` (labels `com.docker.compose.*`), `restart_policy`, `started_at`. Les variables d'environnement et les autres labels ne sont jamais repris : ils contiennent souvent des secrets. Un vhost dont le `ProxyPass` vise un port local publié par un conteneur en marche est de type `docker` (et non `node`) ; le central le relie au seul conteneur qui publie ce port sur loopback ou sur toutes les adresses.

**Pare-feu UFW** (ajout 0.4.0, si `ufw` est installé) : sortie de `ufw status verbose`, lue en anglais (`LANGUAGE=C LC_ALL=C`). On garde `status` (`active`/`inactive`), `logging`, les politiques par défaut `default_incoming`, `default_outgoing`, `default_routed`, et les règles dans l'ordre affiché : `rules: [{to, action, from, ipv6, comment}]` (`action` du type `ALLOW IN`, `LIMIT IN`, `DENY OUT` ; le suffixe `(v6)` devient `ipv6: true` ; commentaire masqué, puis coupé à 255 caractères). UFW inactif : `status: inactive`, aucune règle.

**MySQL/MariaDB** (si `mysql` est installé) :
- Client `mysql --protocol=socket -N -B`, en root par socket Unix. Si `/etc/discover-agent/mysql.cnf` existe, il est passé avec `--defaults-extra-file`.
- `SELECT VERSION()` ;
- les bases (hors `mysql`, `sys`, `information_schema`, `performance_schema`), avec leur taille en octets (`information_schema.tables`) ;
- les comptes : `SELECT user, host FROM mysql.user` (aucune colonne d'authentification) ;
- les droits : `information_schema.SCHEMA_PRIVILEGES`, agrégés par (compte, base), plus les droits globaux `ALL PRIVILEGES` lus dans `USER_PRIVILEGES` et signalés `global: true`.
- En cas d'échec d'accès : une erreur explicite dans `errors[]`, avec un renvoi vers la documentation (création d'un compte en lecture seule).

### 4.5 Format du snapshot (`schema_version: 1`)

```json
{
  "schema_version": 1,
  "collected_at": "2026-10-14T12:00:00Z",
  "agent_version": "0.1.0",
  "server": {"hostname": "web01", "fqdn": "web01.example.lan", "machine_id": "…",
             "primary_ip": "10.0.0.11", "os": "Ubuntu 24.04.1 LTS", "kernel": "6.8.0-45-generic",
             "virtualization": "kvm",
             "interfaces": [{"name": "eth0", "addresses": ["10.0.0.11/24"]}]},
  "apache": {"version": "Apache/2.4.58 (Ubuntu)",
    "sites": [{"server_name": "shop.example.com", "aliases": ["www.shop.example.com"],
               "port": 443, "https": true, "cert_expires_at": "2027-01-01T00:00:00Z",
               "type": "php-fpm", "document_root": "/var/www/shop/public", "app_dir": "/var/www/shop/public",
               "upstream": null, "php_version": "8.3", "system_user": "shop", "system_group": "shop",
               "owner_user": "shop", "owner_group": "www-data", "process_manager": null,
               "config_file": "/etc/apache2/sites-enabled/shop.conf", "partial": false,
               "db_refs": [{"db_name": "shop_prod", "db_user": "shop", "db_host": "localhost",
                            "source": "/var/www/shop/.env"}],
               "technologies": [{"name": "Laravel", "version": "12.3.0"}]}]},
  "mysql": {"version": "8.0.39",
    "databases": [{"name": "shop_prod", "size_bytes": 1048576}],
    "users": [{"user": "shop", "host": "localhost"}],
    "grants": [{"user": "shop", "host": "localhost", "database": "shop_prod", "privileges": ["SELECT","INSERT"], "global": false}]},
  "node": {"apps": [{"name": "api", "version": "1.4.0", "node_version": "20.18.0",
             "listen": [{"address": "127.0.0.1", "port": 3000}], "user": "api", "group": "api",
             "app_dir": "/srv/api", "process_manager": "systemd:api.service",
             "technologies": [{"name": "Express", "version": "4.21.1"}]}]},
  "errors": [{"collector": "mysql", "message": "…"}]
}
```

Si Apache est absent, `apache` vaut `null`. Si MySQL est absent, `mysql` vaut `null`. `technologies` et `node` sont apparus avec l'agent 0.2.0 : le central les accepte absents (agents 0.1.0), sans changer `schema_version`. De même pour `docker` (agent 0.3.0), qui vaut `null` sans Docker, et pour `ufw` (agent 0.4.0), qui vaut `null` sans UFW.

### 4.6 Packaging

- Paquet `discover-agent_<version>_all.deb` qui dépend de `python3 (>= 3.10)`, `iproute2` et `openssl`.
- Le paquet installe le code dans `/usr/lib/discover-agent/`, le lanceur `/usr/bin/discover-agent` et les unités systemd.
- Le `postinst` active le timer seulement si le serveur est déjà enrôlé. `enroll` active le timer.
- Construction par `agent/packaging/build-deb.sh` (dans Docker `ubuntu:24.04` si `dpkg-deb` est absent).

### 4.7 Sections détaillées (agent 0.5)

Ajout rétrocompatible : `schema_version` reste 1, et la clé `sections` est optionnelle sur le central (un agent 0.4 n'en envoie pas). Elle s'ajoute aux clés de 4.5, qui ne changent pas. Détail de conception : `2026-10-31-agent-0.5-design.md`. Le central ne donne aucune instruction à l'agent : sa réponse reste `{"status": "ok"}`.

**Enveloppe** (une par section, 14 au plus : `packages`, `apt_sources`, `kernel`, `restart_needed`, `kernel_surface`, `network`, `php`, `apache`, `site_dependencies`, `node_apps`, `docker`, `persistence`, `integrity`, `code_patterns`) :

```json
{"collected_at": "2026-11-01T08:00:00Z", "sha256": "…", "error": null, "source_errors": {}, "data": {}}
```

| Champ | Règle |
|---|---|
| `collected_at` | Date UTC, présente même en cas d'échec ; absente pour `skipped_not_due`. |
| `error` | `null`, `timeout`, `not_installed`, `skipped_not_due`, `too_large` ou un message (coupé à 4000 caractères, secrets masqués pour les erreurs de commande). |
| `source_errors` | `{sous-source: erreur}` pour les sous-sources non lues d'une section collectée. `{}` sinon. |
| `data` | `null` dès que `error` n'est pas `null`. |
| `sha256` | SHA-256 du JSON canonique de `data` (clés triées, séparateurs compacts, UTF-8) ; `null` si `data` est `null`. |

**Règle de fidélité.** Dans `data`, une liste vide ou un objet vide signifie « lu, rien trouvé ». `null` signifie « non lu » et s'accompagne d'une entrée dans `source_errors` (`not_installed` ou un message). Exception : une des 10 clés PHP toujours présentes, absente d'un fichier `ini`, vaut `null` sans erreur (voir `php`). Les listes sont triées de façon stable : `sha256` ne change que si l'état change. Aucune valeur vivante (PID de worker, compteurs, durée « Up 3 hours ») n'entre dans `data`.

**Limites et rythme.** Délai de 30 s par section (300 s pour `integrity` et `code_patterns`, dites hebdomadaires), 4 Mio par section (`too_large`), 16 Mio par envoi (les plus grosses passent en `too_large`). Les sections hebdomadaires ont leur échéance dans `state.json` (`weekly_interval_days` de `agent.conf`, 7 par défaut ; nouvel essai après 24 h si le résultat est une panne), écrite seulement après un HTTP 200. Chaque exécution note aussi `last_attempt` : si le résultat n'a pas été livré, la section attend 24 h avant d'être recollectée. L'enrôlement n'exécute pas ces sections. Les vraies pannes (message, `timeout`, `too_large`) sont aussi dans `errors[]`.

**Schéma de `data`, par section.** Tous les horodatages sont en UTC (`2026-11-01T08:00:00Z`).

| Section | `data` |
|---|---|
| `packages` | Liste triée par (`package`, `arch`) des paquets `ii` et `hi` : `package`, `version`, `arch`, `source_package`, `source_version`, `status`, `installed_at` (mtime de `/var/lib/dpkg/info/<pkg>[:arch].list`, `null` si absent). |
| `apt_sources` | `{sources, origins, pro}`. `sources` : `[{file, uri, suite, components}]` (formats `.list` et `.sources`, `Enabled: no` ignoré, options et `Signed-By` jamais lus, `uri` masquée). `origins` : pour les paquets `php*`, `nodejs`, `apache2`, `libbrotli1`, `linux-image-*`, `docker-ce`, `[{package, version, origins: [{uri, suite}]}]` (`apt-cache policy`). `pro` : `{attached, services: [{name, status}]}` (`pro status`, délai de 10 s, jamais de jeton, compte ou contrat ; `status` reprend `available` sur une machine non rattachée, qui n'a pas de `status`). |
| `kernel` | `{running, installed, reboot_required, reboot_required_pkgs, boot_time, livepatch}`. `installed` : versions des paquets `linux-image-*`. `livepatch` (`canonical-livepatch status`, `not_installed` sinon) : `{kernel, running, check_state, patch_state, version, fixes: [{name, patched}]}`. |
| `restart_needed` | `{needrestart, masters}`. `needrestart` (`needrestart -b -r l`, lecture seule) : `{kernel_current, kernel_expected, kernel_status, services}`. `masters` : processus maîtres `php-fpm*` et `apache2` `[{comm, pid, started_at}]` (un maître est un processus dont le parent n'a pas le même nom). |
| `kernel_surface` | `{sysctl, modules, modprobe}`. `sysctl` : `{clé: valeur}` pour la liste blanche (`net.mptcp.enabled`, `net.ipv4.tcp_migrate_req`, `kernel.unprivileged_userns_clone`, `user.max_user_namespaces`, `kernel.unprivileged_bpf_disabled`, `kernel.kptr_restrict`, `kernel.dmesg_restrict`, `net.ipv4.ip_forward`, `net.ipv6.conf.all.forwarding`), `null` pour un sysctl inexistant (sans erreur). `modules` : noms de `lsmod`. `modprobe` : lignes `blacklist` et `install` (masquées) de `modprobe --showconfig`, à défaut lues dans `/etc/modprobe.d` et `/lib/modprobe.d`. |
| `network` | `{sockets, mptcp_listeners, nft, iptables, iptables_legacy, fail2ban, ufw, services}`. `fail2ban` (depuis l'agent 0.5.2) : `{enabled, active, jails, config_errors}`, lu par `fail2ban-client -d` donc y compris démon arrêté ; `jails` : `[{name, backend, bantime, findtime, maxretry, ignoreip, logpaths, actions: [{name, port, protocol}]}]` pour les jails activées dans la configuration, sans regex de filtre ni autre option d'action (une clé d'API peut s'y trouver) ; `null` si non installé. Les IP bannies et les compteurs sont des valeurs vivantes : jamais remontés, et les règles `-A f2b-* -s <ip>` d'iptables comme les éléments des ensembles des tables nft `f2b-*` sont retirés. `iptables` et `iptables_legacy` (depuis l'agent 0.5.1) : `{v4, v6}`, chacun la liste ordonnée des lignes de `iptables-save` / `ip6tables-save` et de leurs variantes `-legacy-save`, sans compteurs ni lignes de commentaire datées, `--comment` masqué ; `null` si non lu, avec `source_errors["iptables.v4"]`, etc. `sockets` et `mptcp_listeners` : `[{protocol, address, port, process, pid, public}]` (`ss -ltnupH`, `ss -ltnMH` dont seules les lignes `mptcp` sont gardées) ; `pid` est celui du processus maître du groupe, `public` vaut `false` pour le loopback, `null` si l'adresse n'est pas analysable. `mptcp_listeners` vaut `null` avec `source_errors.mptcp_listeners = "mptcp_diag non chargé"` si `ss` ne montre aucun listener alors que le noyau a MPTCP (`/proc/sys/net/mptcp/enabled`) et que `/sys/module/mptcp_diag` est absent ; un noyau sans MPTCP donne `[]`. `nft` : JSON de `nft -j list ruleset`, sans compteurs, quotas, `last` ni expirations, commentaires masqués. `ufw` : même format que la clé `ufw` de 4.5. `services` : `[{name, enabled, active}]` pour la liste de services de mitigation (`cve98432-filter.service`). |
| `php` | `{versions, sites}`. `versions` : `[{version, sapis, pools}]`. `sapis` : `fpm`, `apache2`, `cli`, chacun `{active, source, values, php_version, build_date, extensions}` ; `source` vaut `php -i` (fpm, cli) ou `ini` (apache2) ; `values` : toutes les directives trouvées (valeur locale, masquée), avec toujours les 10 clés `session.upload_progress.enabled`, `session.serialize_handler`, `session.save_handler`, `session.save_path`, `disable_functions`, `allow_url_include`, `allow_url_fopen`, `open_basedir`, `expose_php`, `display_errors` (`null` si absentes), `values` valant `null` si non lu. `php_version` et `build_date` : tels qu'indiqués par `php -i`. `extensions` : `[{name, versions}]` trié, d'après `-m`, où `versions` reprend les lignes de version du module dans `php -i`, dont les bibliothèques liées (OpenSSL, libxml, cURL) ; `{}` si phpinfo n'en indique aucune. Pour `apache2`, ces trois champs valent toujours `null` : mod_php ne s'interroge pas sans requête web. `active` : master FPM en cours, module chargé et version retenue pour Apache (`null` si indéterminé), `cli` toujours `true` sauf binaire absent (`false`). `pools` : `[{name, file, listen, listen_allowed_clients, user, group, php_values: [{directive, key, value}], settings}]` ; `settings` : `{clé: valeur}` de toutes les autres directives du fichier de pool (`pm`, `security.limit_extensions`, `clear_env`, `chroot`, etc.), masquées, sans jamais les lignes `env[...]`. `sites` : `[{server_name, port, document_root, type, php_version, mode, pool, allow_override}]` (`mode` : `fpm` ou `mod_php` ; `allow_override` du `<Directory>` le plus spécifique, `null` si non résolu). |
| `apache` | `{modules, vhosts, directives}`. `vhosts` : `[{server_name, port, file, line}]`. `directives` : `[{file, line, directive, value, enabled}]` pour `Protocols`, `VirtualDocumentRoot`, `LimitRequestFieldSize`, `AllowOverride` ; `enabled` est faux pour un fichier seulement dans `*-available`. `null` pour `modules` ou `vhosts` si `apache2ctl` a échoué (`not_installed` pour toute la section sans Apache). |
| `site_dependencies` | `[{app_dir, sites: [{server_name, port}], composer, applications}]`, un élément par dossier applicatif PHP/statique. `composer` : `[{name, version, dev}]` (`composer.lock`, `[]` s'il est absent, `null` s'il est illisible, trop gros ou invalide). `applications` : WordPress et phpMyAdmin `[{name, version}]`. |
| `node_apps` | `{apps: [{name, version, exe, exe_deleted, node_version, listen, user, group, app_dir, process_manager, technologies, dependencies}]}`. `dependencies` : `[{name, version}]` de `package-lock.json` (jamais `resolved`), `[]` si absent, `null` si illisible. `node_version` vient du chemin ou de dpkg, jamais de l'exécution de `node`. |
| `docker` | `{version, images, containers}`. `images` : `[{repository, tag, digest, created}]`. `containers` : `[{name, image, image_id, image_digest, state, ports}]` ; `state` et jamais `Status`. Pas d'environnement ni de labels. `not_installed` sans socket. |
| `persistence` | `{crons, units, users, authorized_keys, sudoers, github_runners}`, chacun `null` si illisible. `crons` : `[{file, user, ignored, line}]` (lignes actives masquées ; `ignored` si cron ne lirait pas le fichier). `units` : unités systemd hors paquet, tous types et drop-ins : `[{name, path, exec_start, drop_in}]` (`exec_start` masqué). `users` : comptes de connexion `[{name, uid, shell, home}]` (`/etc/passwd`). `authorized_keys` : `[{user, file, type, fingerprint, certificate, comment}]` (empreinte SHA-256 calculée, `null` pour un certificat, commentaire masqué, options ignorées). `sudoers` : `[{file, ignored, line}]` masquées. `github_runners` : `[{service, directory, version}]` (`version` au mieux). |
| `integrity` (hebdo) | `{changed, missing, modules_checked}`. `changed` : `[{path, package, sensitive}]`, fichiers listés par `debsums -c`, `package` par `dpkg-query -S` (`null` si inconnu), `sensitive` pour `login`, `libpam-runtime`, `util-linux`, `sudo`, `openssh-server`. `missing` : nombre de lignes `missing file` sur stderr. `modules_checked` : `/lib/modules` visible et non vide ; `false` sous `ProtectKernelModules=yes`, où les modules comptent comme manquants et un module altéré n'apparaît pas dans `changed`. Code 2 ou `missing file` sur stderr : résultat. Code 1 ou 3 sans stdout ni `missing file`, code ≥ 4 : la section passe en erreur. Délai dépassé : `timeout`. `not_installed` sans `debsums` : l'agent n'installe rien. |
| `code_patterns` (hebdo) | `{matches, skipped_files, missing_roots, truncated[, reason]}`. `matches` : `[{path, line, pattern}]`, jamais la ligne. `skipped_files` : nombre de fichiers candidats non (entièrement) lus ; `missing_roots` : DocumentRoots absents ; `truncated` au-delà de 10 000 résultats ou à l'échéance (`reason` : `max_matches` ou `deadline`). |

**Note pour les consommateurs.**
- Pour le SAPI `apache2` (mod_php), les valeurs viennent de la lecture de `php.ini` et `conf.d` : une clé `null` signifie « absente des fichiers ini », pas « désactivée », car la valeur par défaut intégrée de PHP s'applique encore. Pour `fpm` et `cli`, `php -i` renvoie `no value` là où la lecture des fichiers donne `""`.
- `network.services` : l'agent ne voit ni le code de sortie ni stderr de `systemctl`, donc `enabled: "not-found"` (unité inconnue) ne se distingue pas d'un `systemctl` défaillant.
- Le masquage (`redact.py`) cherche des formes connues (`-p…`, `--password=…`, `user:mdp@`, `auth=…`, clés contenant `PASS`, `SECRET`, `KEY`, `TOKEN`, `SALT`, hachages crypt, blobs de clés) ; un secret sous une forme inconnue peut passer. La base du central contient une cartographie sensible des serveurs.
- Ordre de livraison : central avant agents ; un central ancien jetterait `sections` sans le signaler.

## 5. Central

### 5.1 Stack

Laravel (dernière version stable), Filament (dernière version stable), Sanctum, MySQL en production, SQLite pour les tests, Pest.

### 5.2 API

| Route | Auth | Entrée | Réponses |
|---|---|---|---|
| `POST /api/v1/enroll` | aucune (jeton d'enrôlement dans le corps) | `{enrollment_token, machine_id, hostname}` | 201 `{server_id, token}` · 410 jeton invalide, expiré ou utilisé · 422 |
| `POST /api/v1/inventory` | `Bearer` (Sanctum, capacité `inventory:write`) | snapshot v1 | 200 `{status:"ok"}` · 401 · 422 · 429 · 413 |

Lors de l'enrôlement, le `machine_id` est enregistré sur le serveur. Ensuite, un inventaire dont le `machine_id` est différent est accepté, mais un avertissement est ajouté au snapshot.

### 5.3 Modèle de données

- `servers` : name, hostname, fqdn, machine_id (unique, nullable avant enrôlement), primary_ip, os, kernel, virtualization, apache_version, mysql_version, agent_version, status (`pending`/`active`/`revoked`), last_seen_at, last_source_ip, notes.
- `enrollment_tokens` : server_id, token_hash (sha256), expires_at, used_at.
- `snapshots` : server_id, received_at, source_ip, schema_version, payload (json), error_count, warnings (json). Rétention de 30 jours, purgés par une commande planifiée.
- État courant, remplacé à chaque réception dans une transaction :
  - `server_ips` (server_id, interface, address, prefix)
  - `sites` (server_id, server_name, aliases json, port, https, cert_expires_at, type, document_root, app_dir, upstream, php_version, system_user, system_group, owner_user, owner_group, process_manager, config_file, partial, technologies json, node_app_id nullable)
  - `node_apps` (server_id, name, version, node_version, listen json, user, group, app_dir, process_manager, technologies json). Un site `node` est relié à l'application qui écoute sur le port de son `upstream`.
  - `site_db_refs` (site_id, db_name, db_user, db_host, source, database_id nullable, db_user_id nullable, resolution `local`/`remote`/`unresolved`)
  - `databases` (server_id, name, size_bytes)
  - `db_users` (server_id, user, host)
  - `db_grants` (db_user_id, database_id nullable, privileges json, global)
- `server_sections` (agent 0.5) : `server_id` (cascade), `section`, `collected_at`, `sha256`, `error`, `source_errors` json, `data` json, `first_seen_at`, `last_seen_at`, index `(server_id, section, id)`. Ce n'est pas un état courant remplacé : c'est un historique. À chaque envoi, si `sha256`, `error` et `source_errors` sont identiques à la dernière ligne de la section, seuls `collected_at` et `last_seen_at` sont mis à jour ; sinon une ligne est ajoutée (une panne aussi, la donnée précédente reste dans l'historique). `skipped_not_due` n'écrit rien. `snapshots.payload` ne contient pas `sections`. La purge conserve la dernière ligne de chaque couple serveur + section et la dernière avec données.

### 5.4 Mise en relation (après chaque réception, et pour tous les serveurs)

Pour chaque `site_db_ref` :
1. `db_host` est `localhost`, `127.0.0.1`, `::1`, vide, un chemin de socket, le hostname/fqdn du serveur ou l'une de ses IP : on cherche la base `db_name` sur le même serveur → `local`.
2. `db_host` correspond à l'IP, au hostname ou au fqdn d'un **autre** serveur inventorié : on cherche la base sur ce serveur → `remote`.
3. Sinon → `unresolved`.

Le compte de base est retrouvé par `user` sur le serveur cible : correspondance exacte de l'hôte d'abord, puis `%`, puis le premier compte trouvé. Comme la résolution d'un serveur dépend des autres, toutes les références de tous les serveurs sont recalculées à chaque réception (volume faible : ~10 serveurs).

### 5.5 Interface Filament

- **Tableau de bord** : compteurs (serveurs, sites, bases) ; serveurs sans envoi depuis plus de 3 h ; certificats qui expirent dans moins de 30 jours ; sites `partial` ; snapshots récents avec erreurs.
- **Serveurs** : liste (nom, IP, OS, dernier envoi, nombre de sites, état). La fiche serveur affiche les infos et IP, avec des onglets : sites, bases, comptes de base, historique des snapshots, et **« État détaillé »** (agent 0.5, lecture seule : pour chaque section connue, la dernière ligne de `server_sections` avec date, état, erreurs et données en JSON échappé ; une section jamais reçue est signalée comme telle ; pas d'historique affiché). Actions : générer un jeton d'enrôlement (affiché une seule fois, avec la commande `enroll` prête à copier), renouveler le jeton agent, révoquer.
- **Sites** : liste globale avec recherche par domaine et alias, et filtres (serveur, type, version PHP, utilisateur système, HTTPS). La fiche affiche la chaîne domaine → serveur → dossier → utilisateur système → base → compte de base.
- **Bases de données** : liste globale, sites liés, filtre « orphelines » (aucun `site_db_ref` résolu).
- **Utilisateurs** : comptes administrateur, avec MFA (application TOTP).

## 6. Tests

- **Agent** : `unittest` (bibliothèque standard). Les parseurs sont testés sur des exemples réels (configs Apache, pools FPM, `wp-config.php`, `.env`, sorties de `ss`, `ip -j` et `mysql`). Le constructeur de snapshot est testé avec des collecteurs simulés.
- **Test d'intégration** : script Docker `ubuntu:24.04` qui contient Apache, PHP-FPM, Node et MariaDB, avec un site de chaque type. Il lance `discover-agent collect` et vérifie le JSON.
- **Central** : Pest. Tests de l'API (enrôlement OK, jeton expiré ou réutilisé, 401 révoqué, 422, persistance et remplacement de l'état), de la mise en relation (local/remote/unresolved, orphelines) et de l'affichage des pages Filament.

## 7. Hors périmètre v1

Autres distributions, Nginx, PostgreSQL et MongoDB, alertes par e-mail, historique des changements, mTLS, rôles multiples, commandes du central vers les agents.

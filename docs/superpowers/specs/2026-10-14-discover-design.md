# Discover — Design (v1)

Date : 2026-10-14
Statut : validé en conversation (parties 1 à 3), relecture écrite déléguée par l'utilisateur.

## 1. Objectif

Centraliser en un seul endroit les informations serveurs **et** applicatives d'un parc d'environ 10 serveurs (majoritairement des VM) sous Ubuntu 24.04 :

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
| API | Validation stricte du JSON, taille maximale 5 Mo, 30 requêtes/minute par jeton. Journal de chaque réception (serveur, date, IP source). |
| Interface | Comptes administrateur avec double authentification (MFA Filament), rôle unique en v1. |
| Restriction IP | Optionnelle, au niveau d'Apache (documentation). |

## 4. Agent

### 4.1 Commandes

| Commande | Rôle |
|---|---|
| `discover-agent enroll --url URL --token TOKEN [--ca-file F] [--pin-sha256 P]` | Enrôle le serveur, écrit la config et les identifiants. |
| `discover-agent collect` | Affiche le snapshot JSON sur stdout sans l'envoyer. |
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
                            "source": "/var/www/shop/.env"}]}]},
  "mysql": {"version": "8.0.39",
    "databases": [{"name": "shop_prod", "size_bytes": 1048576}],
    "users": [{"user": "shop", "host": "localhost"}],
    "grants": [{"user": "shop", "host": "localhost", "database": "shop_prod", "privileges": ["SELECT","INSERT"], "global": false}]},
  "errors": [{"collector": "mysql", "message": "…"}]
}
```

Si Apache est absent, `apache` vaut `null`. Si MySQL est absent, `mysql` vaut `null`.

### 4.6 Packaging

- Paquet `discover-agent_<version>_all.deb` qui dépend de `python3 (>= 3.12)`, `iproute2` et `openssl`.
- Le paquet installe le code dans `/usr/lib/discover-agent/`, le lanceur `/usr/bin/discover-agent` et les unités systemd.
- Le `postinst` active le timer seulement si le serveur est déjà enrôlé. `enroll` active le timer.
- Construction par `agent/packaging/build-deb.sh` (dans Docker `ubuntu:24.04` si `dpkg-deb` est absent).

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
  - `sites` (server_id, server_name, aliases json, port, https, cert_expires_at, type, document_root, app_dir, upstream, php_version, system_user, system_group, owner_user, owner_group, process_manager, config_file, partial)
  - `site_db_refs` (site_id, db_name, db_user, db_host, source, database_id nullable, db_user_id nullable, resolution `local`/`remote`/`unresolved`)
  - `databases` (server_id, name, size_bytes)
  - `db_users` (server_id, user, host)
  - `db_grants` (db_user_id, database_id nullable, privileges json, global)

### 5.4 Mise en relation (après chaque réception, et pour tous les serveurs)

Pour chaque `site_db_ref` :
1. `db_host` est `localhost`, `127.0.0.1`, `::1`, vide, un chemin de socket, le hostname/fqdn du serveur ou l'une de ses IP : on cherche la base `db_name` sur le même serveur → `local`.
2. `db_host` correspond à l'IP, au hostname ou au fqdn d'un **autre** serveur inventorié : on cherche la base sur ce serveur → `remote`.
3. Sinon → `unresolved`.

Le compte de base est retrouvé par `user` sur le serveur cible : correspondance exacte de l'hôte d'abord, puis `%`, puis le premier compte trouvé. Comme la résolution d'un serveur dépend des autres, toutes les références de tous les serveurs sont recalculées à chaque réception (volume faible : ~10 serveurs).

### 5.5 Interface Filament

- **Tableau de bord** : compteurs (serveurs, sites, bases) ; serveurs sans envoi depuis plus de 3 h ; certificats qui expirent dans moins de 30 jours ; sites `partial` ; snapshots récents avec erreurs.
- **Serveurs** : liste (nom, IP, OS, dernier envoi, nombre de sites, état). La fiche serveur affiche les infos et IP, avec des onglets : sites, bases, comptes de base, historique des snapshots. Actions : générer un jeton d'enrôlement (affiché une seule fois, avec la commande `enroll` prête à copier), renouveler le jeton agent, révoquer.
- **Sites** : liste globale avec recherche par domaine et alias, et filtres (serveur, type, version PHP, utilisateur système, HTTPS). La fiche affiche la chaîne domaine → serveur → dossier → utilisateur système → base → compte de base.
- **Bases de données** : liste globale, sites liés, filtre « orphelines » (aucun `site_db_ref` résolu).
- **Utilisateurs** : comptes administrateur, avec MFA (application TOTP).

## 6. Tests

- **Agent** : `unittest` (bibliothèque standard). Les parseurs sont testés sur des exemples réels (configs Apache, pools FPM, `wp-config.php`, `.env`, sorties de `ss`, `ip -j` et `mysql`). Le constructeur de snapshot est testé avec des collecteurs simulés.
- **Test d'intégration** : script Docker `ubuntu:24.04` qui contient Apache, PHP-FPM, Node et MariaDB, avec un site de chaque type. Il lance `discover-agent collect` et vérifie le JSON.
- **Central** : Pest. Tests de l'API (enrôlement OK, jeton expiré ou réutilisé, 401 révoqué, 422, persistance et remplacement de l'état), de la mise en relation (local/remote/unresolved, orphelines) et de l'affichage des pages Filament.

## 7. Hors périmètre v1

Autres distributions, Nginx, PostgreSQL et MongoDB, alertes par e-mail, historique des changements, mTLS, rôles multiples, commandes du central vers les agents.

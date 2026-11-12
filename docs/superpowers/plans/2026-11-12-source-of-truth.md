# StackCard source de vérité : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à chaque serveur un slug figé, exposer slug et adresse de connexion par l'API, publier la liste des domaines (API + admin) et un inventaire Ansible.

**Architecture:** Tout se passe dans `central/` (Laravel 13 + Filament 5). Trois colonnes et un indicateur sur `servers`, une table dérivée `site_domains` remplie par `IngestSnapshot`, deux nouvelles routes dans le groupe de lecture existant (`ServerReadController` garde serveurs/sections ; nouveaux `DomainReadController` et `AnsibleInventoryController`), une ressource Filament en lecture seule « Domaines ». Aucun changement de l'agent ni du contrat de snapshot.

**Tech Stack:** PHP ≥ 8.4, Laravel 13, Filament 5, Sanctum, Pest (SQLite en mémoire), Python 3 stdlib pour le script d'inventaire.

**Spec:** `docs/superpowers/specs/2026-11-12-source-of-truth-design.md`

## Global Constraints

- Tests : `cd central && ./vendor/bin/pest < /dev/null` ; toute la suite reste verte à chaque commit.
- Chaque migration Laravel a son fichier `_release/2026-11-12_NNN.sql` (DDL de `php artisan migrate --pretend` + `INSERT INTO migrations`), voir `_release/README.md`.
- Textes d'interface et de doc en français.
- API de lecture : middleware inchangé (`throttle:read-api`, `auth:sanctum`, `abilities:inventory:read`, `EnsureApiClientIsAllowed`) ; champs listés un par un ; les notes et `machine_id` ne sortent jamais.
- `docs/api/stackcard-read-api.openapi.yaml` et `.postman_collection.json` suivent chaque route et champ ; `ReadApiDocsTest` compare les routes documentées à celles de `ServerReadController` : étendre son filtre à `DomainReadController` et `AnsibleInventoryController`.
- Format du slug : `^(?!\d+$)[a-z0-9]+(?:-[a-z0-9]+)*$`, 64 caractères max.
- Champs et actions sensibles réservés aux admins via `auth()->user()?->isAdmin()`.
- Commits : auteur `Thibault de la Croix`, aucune mention d'IA. Ne pas pousser.

## Review Focus

1. **Nom de serveur sans lettre latine ou fait de chiffres** (« 123 », « —— ») : le slug généré est valide (`srv-123`, `srv`), jamais vide ni purement numérique. → tâche 2.
2. **Domaine écrit avec des majuscules, ou présent à la fois en ServerName et en alias** : une seule entrée en minuscules, un seul emplacement par site. → tâche 5.
3. **Inventaire renvoyé plusieurs fois** : les `site_domains` d'un serveur sont remplacés, jamais accumulés. → tâche 5.
4. **Un compte `user` qui envoie quand même `slug`/`connect_address` dans la requête Livewire** : la valeur n'est pas enregistrée (champ désactivé côté serveur, pas seulement masqué). → tâche 3.
5. **Slug inconnu, ou slug en majuscules, dans `/servers/{server}`** : 404 JSON, jamais 500. → tâche 4.

---

### Task 1: 401 propre sur l'API sans jeton

**Files:**
- Modify: `central/bootstrap/app.php`
- Test: `central/tests/Feature/ReadApiTest.php`

- [ ] **Step 1: Write the failing test** `it('answers 401 in JSON to a request without a token, even without Accept')` : `$this->get('/api/v1/servers')` (pas `getJson`) → `assertStatus(401)` et `assertJsonPath('message', 'Unauthenticated.')`.
- [ ] **Step 2: Run** `./vendor/bin/pest --filter="even without Accept" < /dev/null` → FAIL (500, `Route [login] not defined`).
- [ ] **Step 3: Implement** dans `withMiddleware` : `$middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('filament.admin.auth.login'));`
- [ ] **Step 4: Run** toute la suite → PASS.
- [ ] **Step 5: Commit** `fix(central): answer 401 instead of crashing on unauthenticated API calls`

### Task 2: Slug, adresse et port de connexion sur `servers`

**Files:**
- Create: `central/database/migrations/2026_11_12_000000_add_slug_and_connection_to_servers.php`, `central/app/Support/ServerSlug.php`, `_release/2026-11-12_001.sql`
- Modify: `central/app/Models/Server.php`, `central/app/Actions/IngestSnapshot.php`
- Test: `central/tests/Feature/ServerSlugTest.php`, `central/tests/Feature/InventoryTest.php`

**Interfaces:**
- Produces:
  - Colonnes `servers.slug` (string 64, nullable en base, **unique**), `servers.slug_locked_at` (datetime nullable), `servers.connect_address` (string 255 nullable), `servers.connect_port` (unsignedSmallInteger nullable). *(Décision du plan : `slug` reste nullable en base pour garder le SQL manuel simple ; le modèle garantit qu'il est toujours rempli.)*
  - `ServerSlug::PATTERN` (regex des contraintes globales, sans délimiteurs) ; `ServerSlug::isValid(string $slug): bool` ; `ServerSlug::from(string $name): string` (`Str::slug`, coupé à 64, préfixe `srv-` si purement numérique, `srv` si vide) ; `ServerSlug::unique(string $name, ?int $ignoreId = null): string` (ajoute `-2`, `-3`… jusqu'à trouver un slug libre, en restant ≤ 64).
  - `Server` : `slug`, `connect_address`, `connect_port` dans `$fillable` ; cast `slug_locked_at` datetime ; hook `creating` qui remplit `slug` via `ServerSlug::unique($name)` s'il est vide ; `isSlugLocked(): bool` ; `effectiveAddress(): ?string` (`connect_address ?? primary_ip`) ; `static findByKey(string $key): self` (chiffres seuls → id, sinon slug exact ; `firstOrFail`).
  - `IngestSnapshot::store` pose `slug_locked_at = now()` s'il est nul **et** si le serveur n'avait jamais envoyé (`last_seen_at` nul avant la mise à jour).

- [ ] **Step 1: Write failing tests**
  - `ServerSlug::from('Client X – prod (OVH)')` → `'client-x-prod-ovh'` ; `from('123')` → `'srv-123'` ; `from('——')` → `'srv'` ; `isValid('123')` false ; `isValid('web-01')` true ; `isValid('Web01')` false.
  - avec un serveur `web01` existant, `ServerSlug::unique('web01')` → `'web01-2'`.
  - `it('gives every new server a slug')` : `Server::create(['name' => 'Web 01'])->slug` → `'web-01'`.
  - `it('locks the slug at the first inventory only')` : serveur jamais vu → POST inventaire → `slug_locked_at` non nul ; serveur avec `last_seen_at` déjà posé et `slug_locked_at` nul → POST inventaire → `slug_locked_at` reste nul.
  - `effectiveAddress()` : `connect_address` prioritaire, sinon `primary_ip`.
- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/ServerSlugTest.php < /dev/null` → FAIL.
- [ ] **Step 3: Migration** : `up()` ajoute les 4 colonnes, remplit `slug` des serveurs existants par ordre d'`id` avec `ServerSlug::unique($name, $id)` (sans poser `slug_locked_at`), puis crée l'index unique. `down()` les retire.
- [ ] **Step 4: Implement** `ServerSlug` et les ajouts de `Server` et d'`IngestSnapshot`.
- [ ] **Step 5: `_release/2026-11-12_001.sql`** : DDL de `migrate --pretend` ; `UPDATE servers SET slug = CONCAT('srv-', id) WHERE slug IS NULL;` avant l'index unique ; commentaire en tête : « slugs provisoires : à relire dans l'admin puis figer » ; `INSERT INTO migrations`.
- [ ] **Step 6: Run** toute la suite → PASS.
- [ ] **Step 7: Commit** `feat(central): permanent server slug and manual connection address`

### Task 3: Admin : champs, affichage et « Figer les slugs »

**Files:**
- Modify: `central/app/Filament/Resources/Servers/Schemas/ServerForm.php`, `.../Schemas/ServerInfolist.php`, `.../Tables/ServersTable.php`, `.../Pages/ListServers.php`, `central/tests/Pest.php` (y déplacer `accountWithRole`, `adminUser`, `plainUser` depuis `tests/Feature/Filament/UsersAndSettingsTest.php`)
- Create: `central/app/Filament/Resources/Servers/Actions/LockSlugsAction.php`
- Test: `central/tests/Feature/Filament/ServerSlugAdminTest.php`

**Interfaces:**
- Consumes: tâche 2 (`ServerSlug`, `isSlugLocked()`, `effectiveAddress()`, colonnes).
- Produces: `LockSlugsAction::make(): Action` (en-tête de `ListServers`).

Formulaire :
- `slug` : « Identifiant (slug) », pré-rempli à la création depuis `name` (tant que l'utilisateur n'a pas modifié le slug lui-même), `regex(ServerSlug::PATTERN)`, `unique(ignoreRecord: true)` avec le message « Ce slug est déjà pris. », `maxLength(64)`, `required` ; **disabled** (donc non enregistré) si l'utilisateur n'est pas admin ou si `$record?->isSlugLocked()` ; aide : « Identifiant permanent utilisé par Ansible et les autres outils. Il est figé au premier inventaire. »
- `connect_address` : « Adresse de connexion », aide « Laisser vide pour utiliser l'IP principale remontée par l'agent. », IP ou nom d'hôte RFC 1123, `maxLength(255)`, disabled si non admin.
- `connect_port` : « Port SSH », entier 1–65535, facultatif, disabled si non admin.

Liste et fiche : « Slug » copiable, avec un badge « non figé » (warning) si `slug_locked_at` est nul ; la colonne « IP » devient « Adresse » = `effectiveAddress()`, avec la description « manuelle » quand `connect_address` est posée.

Action « Figer les slugs » : visible si admin **et** s'il existe des serveurs avec `slug_locked_at` nul et `last_seen_at` non nul ; confirmation « Les slugs ne pourront plus être modifiés. Les outils (Ansible, suivi des CVE) pourront s'y fier. » ; pose `slug_locked_at = now()` sur ces serveurs ; notification indiquant combien.

- [ ] **Step 1: Write failing tests** (Livewire, `actingAs(..., 'web')`)
  - un admin crée « Web 01 » → slug `web-01` enregistré ; slug `Web_01` → erreur de validation ; slug déjà pris → erreur.
  - un admin modifie le slug d'un serveur non figé → enregistré ; d'un serveur figé → inchangé.
  - un `plainUser` remplit `slug` et `connect_address` sur EditServer → valeurs inchangées en base (Review Focus 4).
  - `connect_address` `not an address!` → erreur ; `10.0.0.5` et `web01.lan` → OK ; `connect_port` 70000 → erreur.
  - « Figer les slugs » : invisible pour un `plainUser` ; pour un admin, fige les serveurs déjà vus et non figés, et laisse modifiable le serveur en attente (jamais vu).
- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/Filament/ServerSlugAdminTest.php < /dev/null` → FAIL.
- [ ] **Step 3: Implement** formulaire, liste, fiche, action.
- [ ] **Step 4: Run** toute la suite → PASS.
- [ ] **Step 5: Commit** `feat(central): edit, show and lock server slugs in the admin`

### Task 4: API serveurs : slug, adresse, accès par slug

**Files:**
- Modify: `central/app/Http/Controllers/Api/ServerReadController.php`, `central/routes/api.php`, `docs/api/stackcard-read-api.openapi.yaml`, `docs/api/stackcard-read-api.postman_collection.json`
- Test: `central/tests/Feature/ReadApiTest.php`

**Interfaces:**
- Consumes: `Server::findByKey(string $key)`.
- Produces: `summary()` contient en plus `slug`, `slug_locked` (bool), `connect_address`, `connect_port`.

Routes : remplacer `whereNumber('server')` par `where('server', '[a-z0-9-]+')` ; `show` et `section` reçoivent `string $server` et le résolvent par `Server::findByKey`.

- [ ] **Step 1: Write failing tests**
  - liste : `data.0.slug` = `'web01'`, `data.0.slug_locked` false, `data.0.connect_address` null.
  - `servers/web01` et `servers/{id}` renvoient le même `data.id` ; `servers/web01/sections/kernel` répond 200.
  - `servers/inconnu` → 404 JSON ; `servers/WEB01` → 404 (Review Focus 5).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** contrôleur et routes.
- [ ] **Step 4: Docs** : OpenAPI (nouveaux champs dans le schéma serveur ; paramètre `server` décrit comme « identifiant numérique ou slug ») ; Postman : valeur d'exemple de la variable `server` = `web01`.
- [ ] **Step 5: Run** toute la suite (dont `ReadApiDocsTest`) → PASS.
- [ ] **Step 6: Commit** `feat(central): server slug and connection address in the read API`

### Task 5: Table `site_domains` et `GET /api/v1/domains`

**Files:**
- Create: `central/database/migrations/2026_11_12_100000_create_site_domains_table.php`, `central/app/Models/SiteDomain.php`, `central/app/Http/Controllers/Api/DomainReadController.php`, `_release/2026-11-12_002.sql`
- Modify: `central/app/Actions/IngestSnapshot.php` (`storeSites`), `central/app/Models/Site.php` (relation `domains()`), `central/routes/api.php`, `docs/api/*`, `central/tests/Feature/ReadApiDocsTest.php` (filtre étendu)
- Test: `central/tests/Feature/DomainsTest.php`

**Interfaces:**
- Produces:
  - Table `site_domains` : `id`, `site_id` (FK cascade), `server_id` (FK cascade), `domain` (string 255, index), `role` (string 16 : `server_name` | `alias`), timestamps ; unique (`site_id`, `domain`).
  - `SiteDomain` : `belongsTo` `site` et `server` ; constantes `ROLE_SERVER_NAME`, `ROLE_ALIAS` ; `static namesFor(?string $serverName, array $aliases): array<string, string>` (domaine en `mb_strtolower` → rôle ; un nom présent à la fois en ServerName et en alias garde `server_name`).
  - `storeSites` crée les lignes de chaque site via `namesFor`. Elles partent avec les sites (cascade) dans `clearState`.
  - La migration remplit la table pour les sites existants avec `namesFor`.
  - `GET /api/v1/domains[?server=<slug>]` → `{"data": [{"domain": "...", "locations": [{"source": "apache", "server": {"slug", "name"}, "server_stale", "role", "port", "https", "cert_expires_at", "type", "document_root", "app_dir", "upstream", "php_version", "technologies"}]}]}` ; domaines triés ; emplacements triés par slug puis port ; serveurs révoqués exclus ; `?server` inconnu → `data: []`.

- [ ] **Step 1: Write failing tests**
  - après `snapshotFixture()` : `shop.example.com` apparaît **une** fois avec 2 emplacements (ports 80 et 443) ; `www.shop.example.com` a `role: alias`.
  - alias `WWW.Shop.Example.com` sur le site de `www.shop.example.com` → une seule entrée en minuscules (Review Focus 2).
  - deux POST d'inventaire → même nombre de lignes `site_domains` (Review Focus 3).
  - deux serveurs servant `shop.example.com` → une entrée, deux slugs ; serveur révoqué → absent ; `?server=web01` → seulement ses domaines.
  - sans jeton → 401 ; IP non autorisée → 403 (comme `/servers`).
  - liste fermée des champs : `document_root` présent, `notes` et `machine_id` absents du corps.
- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/DomainsTest.php < /dev/null` → FAIL.
- [ ] **Step 3: Implement** migration, modèle, ingestion, contrôleur, route (dans le groupe de lecture existant).
- [ ] **Step 4: `_release/2026-11-12_002.sql`** : DDL + `INSERT INTO migrations` ; commentaire : « la table se remplit au prochain inventaire de chaque serveur (≤ 1 h) » (le remplissage depuis le JSON des alias n'est pas fait en SQL).
- [ ] **Step 5: Docs** : chemin `/api/v1/domains` et son schéma dans l'OpenAPI ; dossier Postman « Domaines » avec la requête.
- [ ] **Step 6: Run** toute la suite → PASS.
- [ ] **Step 7: Commit** `feat(central): fleet-wide domain list in the read API`

### Task 6: Page « Domaines » dans l'admin

**Files:**
- Create: `central/app/Filament/Resources/Domains/DomainResource.php`, `.../Pages/ListDomains.php`, `.../Tables/DomainsTable.php`
- Test: `central/tests/Feature/Filament/DomainsPageTest.php`

**Interfaces:**
- Consumes: `SiteDomain` (tâche 5).

Ressource sur `SiteDomain`, en lecture seule (`canCreate` false, pas d'édition), libellés « domaine »/« domaines », icône globe, placée juste après Sites dans la navigation, accessible à tous les rôles. Une ligne par `site_domains` : colonnes Domaine (recherche), Rôle (« principal »/« alias »), Serveur (slug et nom, lien vers la fiche du serveur), Port, HTTPS, Type, Technos, Certificat (rouge à moins de 30 jours, comme `SitesTable`) ; tri par défaut sur le domaine ; filtres Serveur, Type, HTTPS ; clic sur une ligne → fiche du site. Serveurs révoqués exclus de la requête.

- [ ] **Step 1: Write failing tests** : un `plainUser` voit la page ; la recherche `shop` trouve `shop.example.com` et `www.shop.example.com` ; les domaines d'un serveur révoqué n'y figurent pas.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** la ressource.
- [ ] **Step 4: Run** toute la suite → PASS.
- [ ] **Step 5: Commit** `feat(central): Domains page in the admin`

### Task 7: Inventaire Ansible

**Files:**
- Create: `central/app/Http/Controllers/Api/AnsibleInventoryController.php`, `docs/ansible/stackcard_inventory.py`, `docs/ansible/test_stackcard_inventory.py`, `docs/ansible.md`
- Modify: `central/routes/api.php`, `docs/api/*`
- Test: `central/tests/Feature/AnsibleInventoryTest.php`, `docs/ansible/test_stackcard_inventory.py`

**Interfaces:**
- Consumes: `Server::effectiveAddress()`, `isStale()`, `slug`.
- Produces: `GET /api/v1/inventory/ansible` →
  ```json
  {"_meta": {"hostvars": {"web01": {"ansible_host": "203.0.113.5", "stackcard_id": 1, "stackcard_name": "web01", "stackcard_hostname": "web01", "stackcard_fqdn": null, "stackcard_os": "Ubuntu 24.04.1 LTS", "stackcard_stale": false, "stackcard_last_seen_at": "2026-11-12T08:00:00.000000Z"}}},
   "all": {"children": ["ungrouped", "apache", "mysql", "ubuntu_24_04"]},
   "ungrouped": {"hosts": []},
   "apache": {"hosts": ["web01"]}, "mysql": {"hosts": ["web01"]}, "ubuntu_24_04": {"hosts": ["web01"]}}
  ```
  `ansible_port` seulement si `connect_port` est posé. Groupe OS : `ubuntu_XX_YY` tiré de `/Ubuntu (\d{2})\.(\d{2})/` dans `os` ; autres groupes `apache`, `mysql`, `docker` (version non nulle) et `stale`. Seuls les groupes non vides sont émis, triés par nom. Hôtes : `status = active` avec une adresse effective, triés par slug.
- Script : `stackcard_inventory.py --list` imprime le JSON de l'API ; `--host <slug>` imprime `{}` (les hostvars sont dans `_meta`). Lit `STACKCARD_URL` (base du central) et `STACKCARD_TOKEN` ; refuse une URL non HTTPS ; vérifie le certificat, avec `STACKCARD_CA_FILE` facultatif pour une CA interne ; en cas de variable manquante ou de réponse non 200, message clair sur stderr et code de sortie 1. La fonction de requête prend un `opener` injectable pour les tests.

- [ ] **Step 1: Write failing tests (PHP)** : hôte nommé par son slug avec `ansible_host` = `primary_ip` ; `connect_address`/`connect_port` → `ansible_host`/`ansible_port` ; serveur en attente, révoqué ou sans adresse → absent ; `ubuntu_24_04` contient `web01` ; serveur stale → présent, `stackcard_stale: true`, dans `stale` ; 401 sans jeton.
- [ ] **Step 2: Write failing tests (Python)** : avec un opener factice, `--list` imprime le JSON reçu ; `--host x` imprime `{}` ; `STACKCARD_TOKEN` absent → code 1 et message ; URL `http://` → code 1.
- [ ] **Step 3: Run** `./vendor/bin/pest tests/Feature/AnsibleInventoryTest.php < /dev/null` et `cd docs/ansible && python3 -m unittest` → FAIL.
- [ ] **Step 4: Implement** contrôleur, route `inventory/ansible` (dans le groupe de lecture), script.
- [ ] **Step 5: Docs** : `docs/ansible.md` (créer le client API avec l'IP de Semaphore ; déclarer `STACKCARD_URL` et `STACKCARD_TOKEN` dans l'environnement Semaphore ; déposer le script dans le dépôt de playbooks et le choisir comme inventaire de type fichier ; exemple `ansible -i stackcard_inventory.py ubuntu_22_04 -m ping`) ; OpenAPI + Postman pour la route.
- [ ] **Step 6: Run** toute la suite PHP et les tests Python → PASS.
- [ ] **Step 7: Commit** `feat(central): Ansible dynamic inventory from the read API`

### Task 8: Documentation transverse

**Files:**
- Modify: `CLAUDE.md`, `docs/install-agent.md`, `central/app/Filament/Pages/Commands.php`
- Test: `central/tests/Feature/Filament/CommandsPageTest.php`

- [ ] **Step 1: Write the failing test** : la page « Commandes » contient « Réinstaller un serveur » et « régénérer un jeton sur sa fiche ».
- [ ] **Step 2: Implement**
  - `Commands.php`, groupe « Agent : installer et mettre à jour » : entrée « Réinstaller un serveur : régénérer un jeton sur sa fiche (le slug est conservé), ne pas créer un nouveau serveur », commande `sudo discover-agent enroll --url {$url} --token <jeton>`.
  - `docs/install-agent.md` §2 : même consigne.
  - `CLAUDE.md`, « Central architecture » : le slug est l'identifiant de référence de tous les outils, figé par `slug_locked_at` et jamais modifié ensuite ; `connect_address`/`connect_port` modifiables par les admins seulement ; `site_domains` est dérivée et remplacée à chaque inventaire ; routes `/domains` et `/inventory/ansible` ; `docs/ansible.md`.
- [ ] **Step 3: Run** toute la suite → PASS.
- [ ] **Step 4: Commit** `docs: server slug rule, reinstall procedure and Ansible inventory`

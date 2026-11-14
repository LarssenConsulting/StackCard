# Mises à jour APT : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** L'agent 0.6.0 envoie les mises à jour APT en attente (section `apt_updates`) ; le central les stocke, les montre par serveur et par paquet, les expose par l'API (dont `/packages` pour l'outil de suivi des CVE) et en tire des groupes Ansible.

**Architecture:** Agent : un module de section `apt_updates.py` (rafraîchissement conditionnel du cache, simulation `apt-get -s`, `apt-mark showhold`), un réglage `apt_refresh_hours` dans `agent.conf`. Central : la section suit le circuit existant (`server_sections`), puis `IngestSnapshot` dérive une table `server_updates` et des colonnes de synthèse sur `servers` ; `Server::updatesState()` porte la règle de fraîcheur unique ; nouvelles routes dans le groupe de lecture ; ressource Filament « Mises à jour », onglet de fiche, colonne de liste ; groupes dans `AnsibleInventoryController`.

**Tech Stack:** Python ≥ 3.10 stdlib (unittest), PHP ≥ 8.4, Laravel 13, Filament 5, Pest.

**Spec:** `docs/superpowers/specs/2026-11-14-apt-updates-design.md`

## Global Constraints

- Agent : stdlib uniquement ; tout appel système via `util.run` / `util.run_c` / `util.run_full` ; collecteurs à `runner`/`reader` injectables, tests sur des faux, jamais le vrai système. Tests : `cd agent && python3 -m unittest`.
- Agent : binaires en chemin absolu (`/usr/bin/apt-get`, `/usr/bin/apt-mark`), locale C, environnement propre. **Seules commandes APT autorisées : `apt-get update` (si dû), `apt-get -s …` (simulation) et `apt-mark showhold`.** Jamais d'attente de verrou.
- Section : enveloppe standard, règle de fidélité (`[]` lu et vide, `null` non lu + `source_errors`), listes triées, aucune valeur changeant à chaque passage dans `data`.
- Central : tests `cd central && ./vendor/bin/pest < /dev/null` verts ; migration + `_release/2026-11-14_NNN.sql` ; `docs/api/` (OpenAPI + Postman) suit chaque route et champ ; `ReadApiDocsTest` filtre étendu aux nouveaux contrôleurs ; champs API listés un par un ; textes en français.
- Dépôt **public** : aucun nom de serveur réel, de client, IP réelle (seulement 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 10.x d'exemple), ni secret, y compris dans les messages de commit.
- **Ne pas commiter** (l'utilisateur commite sur demande). Ne pas pousser.
- Version agent : `0.6.0` (`agent/discover_agent/__init__.py`).

## Review Focus

1. **APT verrouillé à chaque passage** (`unattended-upgrades` tient le verrou) : la section reste valide avec le cache existant, `source_errors.apt_update = "locked"`, jamais d'attente. → tâche 2.
2. **Sortie `apt-get -s` inattendue** (nouveau paquet sans version installée, lignes `Conf`/`Remv`, dépôt local sans suite) : pas d'exception, `installed: null` pour un nouveau paquet, `Conf`/`Remv` ignorées. → tâche 2.
3. **Section `apt_updates` en erreur après une section valide** : lignes dérivées et compteurs gardent les dernières données, état `stale`, jamais affiché « à jour ». → tâches 3 et 5.
4. **Serveur avec agent < 0.6** : état `unknown`, compteurs `null` dans l'API, badge « inconnu », absent des groupes Ansible de mises à jour. → tâches 3, 4, 6.
5. **`/packages?name=` invalide ou tableau** (`name[]=x`, vide, caractères hors nom de paquet Debian) : 422 JSON, jamais 500. → tâche 4.

---

### Task 1: Agent : réglage `apt_refresh_hours`

**Files:**
- Modify: `agent/discover_agent/config.py`
- Test: `agent/tests/test_config.py` (nouveau)

**Interfaces:**
- Produces: `config.DEFAULT_APT_REFRESH_HOURS = 6` ; `Config.apt_refresh_hours: int = 6` ; `load()` lit `[collect] apt_refresh_hours` (absent, non entier ou < 1 → 6) ; `save()` l'écrit ; `config.read_apt_refresh_hours(paths: Paths) -> int` lit ce seul réglage même sans enrôlement (fichier absent ou illisible → 6), pour la section.

- [ ] **Step 1: Write failing tests** : `apt_refresh_hours = 1` → 1 ; `0`, `-3`, `abc`, absent → 6 ; `save` puis `load` conserve 2 ; `read_apt_refresh_hours` sur un répertoire sans `agent.conf` → 6 ; `weekly_interval_days` inchangé.
- [ ] **Step 2: Run** `cd agent && python3 -m unittest tests.test_config -v` → FAIL.
- [ ] **Step 3: Implement** dans `config.py`, sur le modèle de `weekly_interval_days`.
- [ ] **Step 4: Run** toute la suite agent → PASS.

### Task 2: Agent : section `apt_updates`

**Files:**
- Create: `agent/discover_agent/sections/apt_updates.py`, `agent/tests/test_section_apt_updates.py`, fixtures sous `agent/tests/fixtures/apt/` (sorties `apt-get -s dist-upgrade` et `apt-mark showhold` réalistes, avec des noms de paquets publics uniquement)
- Modify: `agent/discover_agent/sections/core.py` (`SECTION_NAMES`, délai), `agent/discover_agent/sections/__init__.py` (import + `REGISTRY`), `agent/discover_agent/__init__.py` (`0.6.0`)

**Interfaces:**
- Consumes: `config.read_apt_refresh_hours(paths)`, `core.read_source`, `core.describe`, `core.Collected`, `tools.run_tool`, `util.run_full`, `util.c_locale_env`.
- Produces:
  - `SECTION_NAMES` : `"apt_updates"` juste après `"apt_sources"`. `TIMEOUT_APT = 180` et `timeout_for("apt_updates") == 180`. Section horaire (pas dans `WEEKLY`).
  - `apt_updates.REFRESH_TIMEOUT = 120`.
  - `apt_updates.cache_updated_at(stat=os.stat, listdir=os.listdir) -> datetime | None` : mtime de `/var/lib/apt/periodic/update-success-stamp` ; à défaut, mtime le plus récent des fichiers `*InRelease` / `*_Release` de `/var/lib/apt/lists/` ; `None` si rien.
  - `apt_updates.parse_simulation(text: str) -> list[dict]` : lignes `Inst` seulement. Forme : `Inst <pkg> [<installed>] (<candidate> <Origine:ver/suite>[, <Origine:ver/suite>…] [<arch>])` ; `[<installed>]` absent → `installed: None`. Élément : `{"package", "arch", "installed", "candidate", "origins": [suites triées et dédoublonnées, ex. "noble-security"], "security": bool}` ; `security` vrai si une suite se termine par `-security` (couvre `noble-security`, `noble-apps-security`, `noble-infra-security`). Une origine sans `/` garde son libellé tel quel. Trié par `(package, arch)`.
  - `apt_updates.parse_held(text: str) -> list[str]` : noms triés, lignes vides ignorées.
  - `apt_updates.collect(runner=util.run_c, run_full=util.run_full, now=util.now_utc, refresh_hours=None, stat=os.stat, listdir=os.listdir) -> Collected` :
    1. `hours = refresh_hours or config.read_apt_refresh_hours(config.Paths())`.
    2. Si cache `None` ou plus vieux que `hours` : `run_full(["/usr/bin/apt-get", "update", "-q"], timeout=REFRESH_TIMEOUT, env=<locale C, PATH système fixe, sans les autres variables>)`. Code ≠ 0 avec `Could not get lock` ou `is held by process` dans stderr → `source_errors["apt_update"] = "locked"` ; autre code ≠ 0 → message court (via `describe`) ; délai dépassé → `"timeout"`. Pas de seconde tentative.
    3. `upgrades = read_source(errors, "upgrades", lambda: parse_simulation(run_tool(runner, ["/usr/bin/apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"], timeout=60)))`.
    4. `held = read_source(errors, "held", lambda: parse_held(run_tool(runner, ["/usr/bin/apt-mark", "showhold"])))`.
    5. `data = {"cache_updated_at": <iso UTC du cache relu après l'éventuel rafraîchissement, ou None>, "upgrades": upgrades, "held": held}`.
    6. `/usr/bin/apt-get` absent (les deux sous-sources `not_installed`) → lever `util.NotInstalled` (section `not_installed`).

- [ ] **Step 1: Write failing tests** (faux `runner`/`run_full`/`stat`/`listdir`/`now`, aucun appel réel) :
  - `parse_simulation` sur la fixture 24.04 : `openssl` `3.0.13-0ubuntu3.4` → `3.0.13-0ubuntu3.5`, origines `["noble-security", "noble-updates"]`, `security: true` ; un paquet `noble-updates` seul → `false` ; un nouveau noyau sans `[installed]` → `installed: None` ; `Conf`/`Remv` ignorées ; sortie vide → `[]` (Review Focus 2).
  - cache de 2 h, réglage 6 → `apt-get update` **non** appelé ; cache de 7 h → appelé une fois avec exactement `["/usr/bin/apt-get", "update", "-q"]` ; cache `None` → appelé.
  - `update` : code 100 + `E: Could not get lock /var/lib/apt/lists/lock` → `source_errors == {"apt_update": "locked"}`, `upgrades` lu quand même (Review Focus 1).
  - délai dépassé → `source_errors["apt_update"] == "timeout"`.
  - **invariant** : sur l'ensemble des tests, les commandes reçues par les faux sont uniquement `apt-get update -q`, `apt-get -s -o Debug::NoLocking=1 dist-upgrade` et `apt-mark showhold` ; aucune `install`, `upgrade` sans `-s`, `remove`, `purge`, ni `dpkg`.
  - `refresh_hours=None` lit `config.read_apt_refresh_hours`.
  - `timeout_for("apt_updates") == 180` ; position dans `SECTION_NAMES` ; présence dans `REGISTRY`.
  - `data` n'a que les clés `cache_updated_at`, `upgrades`, `held`.
- [ ] **Step 2: Run** `cd agent && python3 -m unittest tests.test_section_apt_updates -v` → FAIL.
- [ ] **Step 3: Implement** le module, `core.py`, `__init__.py`, version `0.6.0`.
- [ ] **Step 4: Run** toute la suite agent (dont `test_sections_runner`, `test_snapshot`, `test_acceptance`) → PASS ; ajuster les tests qui énumèrent les sections.

### Task 3: Central : stockage et règle de fraîcheur

**Files:**
- Create: `central/database/migrations/2026_11_14_000000_add_apt_updates.php`, `central/app/Models/ServerUpdate.php`, `_release/2026-11-14_001.sql`, `central/tests/Feature/AptUpdatesIngestTest.php`
- Modify: `central/app/Models/ServerSection.php` (`NAMES` : `apt_updates` après `apt_sources`), `central/app/Actions/IngestSnapshot.php`, `central/app/Models/Server.php`, `central/tests/Fixtures/snapshot.json` (section `apt_updates` : 2 mises à jour dont 1 sécurité, `held` d'un paquet ; `kernel.data.reboot_required: true` et un paquet), `docs/api/stackcard-read-api.openapi.yaml` (énumération `SectionName` et tableau des sections), Postman (requête `apt_updates` du dossier Sections)

**Interfaces:**
- Produces:
  - Table `server_updates` : `id`, `server_id` (FK cascade), `package` (string 255, index), `arch` (string 32), `installed_version` (string 255 nullable), `candidate_version` (string 255), `security` (bool), `origins` (json), timestamps ; unique (`server_id`, `package`, `arch`) ; collation binaire sur MySQL/MariaDB pour `package` (conditionnelle au driver, comme `site_domains.domain`).
  - Colonnes `servers` : `updates_count` et `security_updates_count` (unsigned int nullable), `apt_cache_updated_at` (datetime nullable), `updates_received_at` (datetime nullable), `updates_error` (string 4000 nullable), `reboot_required` (bool nullable). Non fillable (écrites par `forceFill`/l'ingestion).
  - `ServerUpdate` : `belongsTo(Server)`, casts `security` bool, `origins` array ; `Server::updates(): HasMany`.
  - `Server::UPDATES_STALE_AFTER_HOURS = 24` ; `Server::updatesState(): string` → `'unknown'` si `updates_received_at` nul ; `'stale'` si `updates_error` non nul, ou `apt_cache_updated_at` nul ou antérieur à `now() - 24 h` ; sinon `'ok'`.
  - `IngestSnapshot`, après `storeSections` :
    - section `apt_updates` sans erreur et `data.upgrades` non nul → remplacer les `server_updates` du serveur ; `updates_count`, `security_updates_count`, `apt_cache_updated_at = data.cache_updated_at`, `updates_received_at = now()`, `updates_error = null`. Une entrée `source_errors.apt_update` seule n'est pas une erreur (l'âge du cache le montre).
    - section en erreur, ou `upgrades` nul → rien remplacé ; `updates_error` = erreur de la section, sinon `source_errors.upgrades`.
    - absente ou `skipped_not_due` → rien.
    - section `kernel` sans erreur → `reboot_required = data.reboot_required` (bool ou nul).
  - Migration sans remplissage ; `_release` : DDL + `INSERT INTO migrations`.

- [ ] **Step 1: Write failing tests** :
  - inventaire de la fixture (avec `cache_updated_at` réécrit à `now() - 1 h` dans le test) → 2 lignes, compteurs 2 / 1, `reboot_required` vrai, `updatesState() === 'ok'`.
  - deux inventaires → toujours 2 lignes.
  - section valide puis `error: "timeout"` → lignes et compteurs conservés, `updates_error === 'timeout'`, `stale` (Review Focus 3).
  - cache de 30 h → `stale`.
  - snapshot sans `apt_updates` → `unknown`, compteurs nuls (Review Focus 4).
  - `source_errors.apt_update = "locked"` seul, cache récent → `ok`.
- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/AptUpdatesIngestTest.php < /dev/null` → FAIL.
- [ ] **Step 3: Implement** migration, modèles, `IngestSnapshot`, `NAMES`, fixture, `_release`, docs API des sections.
- [ ] **Step 4: Run** toute la suite → PASS.

### Task 4: Central : API

**Files:**
- Create: `central/app/Http/Controllers/Api/UpdateReadController.php`, `central/tests/Feature/UpdatesApiTest.php`
- Modify: `central/app/Http/Controllers/Api/ServerReadController.php`, `central/routes/api.php`, `central/tests/Feature/ReadApiDocsTest.php` (filtre), `docs/api/*`

**Interfaces:**
- Consumes: `Server::updatesState()`, `Server::updates()`, `ServerUpdate`, `Server::findByKey`.
- Produces:
  - `summary()` : `updates_state`, `updates_pending` (= `updates_count`, `null` si `unknown`), `security_updates_pending`, `reboot_required`, `apt_cache_updated_at`.
  - `GET /api/v1/servers/{server}/updates` (`UpdateReadController@server`) → `{"data": {"slug", "updates_state", "apt_cache_updated_at", "updates_received_at", "updates_error", "reboot_required", "reboot_required_packages", "held", "updates": [{"package", "arch", "installed_version", "candidate_version", "security", "origins"}]}}` ; `reboot_required_packages` depuis la dernière section `kernel`, `held` depuis la dernière section `apt_updates` valide ; mises à jour triées par paquet.
  - `GET /api/v1/updates[?security=1][&package=<nom>]` (`UpdateReadController@fleet`) → `{"data": [{"package", "candidate_version", "security", "installed_versions": [...], "servers": [{"slug", "name", "installed_version", "updates_state"}]}]}` ; une entrée par (paquet, version candidate), triée par paquet ; serveurs révoqués exclus ; `package` exact ; paramètres non chaîne → 422.
  - `GET /api/v1/packages?name=<nom>` (`UpdateReadController@package`) : `name` obligatoire, chaîne, `^[a-z0-9][a-z0-9.+-]+$`, sinon 422 JSON en français. → `{"data": [{"slug", "name", "os", "installed_version", "candidate_version", "security", "updates_state", "apt_cache_updated_at", "packages_collected_at"}]}` pour chaque serveur non révoqué qui a ce paquet : `installed_version` de `server_updates` si le paquet y figure, sinon de la dernière section `packages` (`data[].version` où `package == name`, première architecture après tri) ; `candidate_version` et `security` `null` hors `server_updates`. Serveur ni dans l'un ni dans l'autre → absent.
  - Routes dans le groupe de lecture existant.

- [ ] **Step 1: Write failing tests** :
  - `/servers` : `updates_state` `ok`, 2 / 1, `reboot_required` vrai ; serveur sans section → `unknown`, `updates_pending` nul.
  - `/servers/web01/updates` : détail trié, `held`, `reboot_required_packages`.
  - `/updates?security=1` : seulement l'entrée sécurité ; deux serveurs avec la même version candidate → une entrée, deux serveurs ; serveur révoqué exclu.
  - `/packages?name=openssl` : installé et candidat ; un paquet présent dans la section `packages` mais à jour → `candidate_version` nul, `updates_state` `ok`.
  - `/packages` sans `name`, `name[]=x`, `name=Bad Name` → 422 JSON (Review Focus 5) ; 401 sans jeton ; 403 hors IP ; aucun champ hors liste (`notes`, `machine_id` absents).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** contrôleur, routes, résumé.
- [ ] **Step 4: Docs** : OpenAPI (3 chemins, schémas, champs du résumé), Postman (dossier « Mises à jour »).
- [ ] **Step 5: Run** toute la suite → PASS.

### Task 5: Central : admin

**Files:**
- Create: `central/app/Filament/Resources/Servers/RelationManagers/UpdatesRelationManager.php`, `central/app/Filament/Resources/Updates/UpdateResource.php`, `.../Updates/Pages/ListUpdates.php`, `.../Updates/Tables/UpdatesTable.php`, `central/tests/Feature/Filament/UpdatesAdminTest.php`
- Modify: `central/app/Filament/Resources/Servers/Tables/ServersTable.php`, `central/app/Filament/Resources/Servers/ServerResource.php` (relation manager après Sites), ordre de navigation si besoin

**Interfaces:**
- Consumes: `Server::updatesState()`, colonnes de la tâche 3, `ServerUpdate`.

Liste des serveurs, colonne « Mises à jour » (badge) : `unknown` → « inconnu » gris ; `ok` et 0 → « à jour » vert ; N > 0 → « N » orange, ou « N dont M sécu » rouge si M > 0 ; `stale` → même texte suivi de « (au JJ/MM) », et gris quand N = 0 (jamais « à jour » vert). Infobulle : âge du cache APT, erreur éventuelle. Mention « redémarrage requis » si `reboot_required`. Filtres : « Mises à jour de sécurité » (`security_updates_count > 0`), « Redémarrage requis ».

Onglet fiche « Mises à jour » (`UpdatesRelationManager`, lecture seule) : Paquet (recherche), Installé, Disponible, Sécurité (badge), Origines ; filtre sécurité ; description : état, âge du cache, erreur, redémarrage requis.

Page flotte « Mises à jour » (`UpdateResource` sur `ServerUpdate`, lecture seule, tous les rôles, juste après « Domaines ») : une ligne par (paquet, version candidate), même technique que la page Domaines (`MIN(id)` groupé, sans GROUP BY extérieur) ; colonnes Paquet (recherche), Version(s) installée(s) → Disponible, Sécurité, Serveurs (nombre + slugs liés aux fiches) ; filtre sécurité ; serveurs révoqués exclus.

- [ ] **Step 1: Write failing tests** : un `plainUser` voit la page flotte et l'onglet ; page flotte : une ligne `openssl` avec 2 serveurs ; colonne de liste (`assertTableColumnStateSet`) : « inconnu », « à jour », « 2 dont 1 sécu » ; serveur `stale` à 0 ne montre jamais « à jour » seul (Review Focus 3) ; filtre sécurité.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** toute la suite → PASS.

### Task 6: Ansible et documentation

**Files:**
- Modify: `central/app/Http/Controllers/Api/AnsibleInventoryController.php`, `central/tests/Feature/AnsibleInventoryTest.php`, `docs/ansible.md`, `docs/install-agent.md`, `docs/superpowers/specs/2026-10-31-agent-0.5-design.md` (renvoi vers le schéma `apt_updates`), `CLAUDE.md`, `docs/api/stackcard-read-api.openapi.yaml` (hostvars de l'inventaire)

**Interfaces:**
- Consumes: colonnes de la tâche 3.
- Produces : groupes `updates_pending` (`updates_count > 0`), `security_updates` (`security_updates_count > 0`), `reboot_required` (`reboot_required` vrai), depuis les dernières données connues quel que soit l'état ; serveur `unknown` → aucun de ces groupes ; groupes vides non émis. Hostvars : `stackcard_updates_state`, `stackcard_updates_pending`, `stackcard_security_updates_pending`, `stackcard_reboot_required`.

- [ ] **Step 1: Write failing tests** : serveur 2 / 1 / redémarrage → dans les trois groupes ; serveur `stale` avec des mises à jour → toujours dans `updates_pending` ; serveur `unknown` → aucun (Review Focus 4) ; groupes vides absents.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** suite → PASS.
- [ ] **Step 5: Docs** :
  - `docs/ansible.md` : `apt_refresh_hours` (défaut 6, minimum 1, relu à chaque passage), playbook d'exemple `community.general.ini_file` (`path: /etc/discover-agent/agent.conf`, `section: collect`, `option: apt_refresh_hours`), groupes `updates_pending`, `security_updates`, `reboot_required` et exemple `hosts: security_updates`.
  - `docs/install-agent.md` : section « Mises à jour APT » (commandes lancées, verrou jamais attendu, réglage).
  - `CLAUDE.md` : `apt_updates` dans la liste des sections et ses invariants (commandes APT autorisées, jamais d'attente de verrou, `apt_refresh_hours`) ; côté central `server_updates`, `Server::updatesState()`, routes `/servers/{server}/updates`, `/updates`, `/packages`.
- [ ] **Step 6: Run** suites agent et central → PASS.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Discover inventories Ubuntu 22.04, 24.04 and 26.04 servers (name, IPs, Apache sites, MySQL databases, the system and DB users tied to each site) and centralises them. Two independent apps share one contract:

- `agent/` — Python agent, **standard library only**, Python ≥ 3.10 (Ubuntu 22.04, 24.04 and 26.04 ship 3.10, 3.12 and 3.14; CI runs all three) (runs as root on servers; no pip installs). Push-only: no listening port, never accepts commands.
- `central/` — Laravel 13 + Filament 5 + Sanctum (PHP ≥ 8.4), receives snapshots and shows them.
- `docs/superpowers/specs/2026-10-14-discover-design.md` is the design authority; user-facing docs are in `docs/install-*.md` (French). UI strings and agent messages are French.

## Commands

```bash
# Agent (run from agent/)
python3 -m unittest                                   # all tests
python3 -m discover_agent collect --full              # prints the snapshot incl. the weekly sections (integrity, code_patterns); plain `collect` skips them and never touches state.json
python3 -m unittest tests.test_apache.VhostTest.test_https_php_fpm_block   # one test
python3 packaging/build_deb.py                        # -> agent/dist/discover-agent_<ver>_all.deb (pure Python, no dpkg needed)
python3 packaging/build_release.py                    # -> agent/dist/release/agent/ (install.sh, latest .deb + .sha256); CI publishes it as a GitHub release on tag agent-v<version>
sh integration/run.sh                                 # Ubuntu 24.04 end-to-end check, needs Docker

# Central (run from central/)
./vendor/bin/pest < /dev/null                         # all tests (SQLite in memory)
./vendor/bin/pest --filter="links a site"             # one test
php artisan serve                                     # /admin (MFA is required on first login)
php artisan make:filament-user                        # create an account: admin if it is the first, otherwise `user`
php artisan discover:prune-snapshots                  # retention (also scheduled daily 03:00)
php artisan db:seed --class=DemoServerSeeder          # fake server demo-web01 (PHP + Node sites, Docker containers), from database/seeders/demo/snapshot.json
./deploy.sh                                           # production update: pull, composer install if composer.lock changed, migrate, optimize, forget the cached latest agent version (never as root)
```

Avoid interactive commands (e.g. `pest --init`): redirect stdin from `/dev/null`.

## The snapshot contract (schema_version 1)

The agent's JSON is built in `agent/discover_agent/snapshot.py` from the collectors; the central validates it in `central/app/Http/Requests/InventoryRequest.php` and stores only the validated subset. Any field change must touch both sides, `central/tests/Fixtures/snapshot.json`, and bump `schema_version` if it is not backward compatible. Site keys come from `collectors/sites.py::_empty_site` plus `db_refs` and `technologies`. `technologies` and the top-level `node` section were added by agent 0.2.0, the top-level `docker` section by 0.3.0, the top-level `ufw` section by 0.4.0; all are optional on the central so older agents are still accepted.

Agent 0.5 adds the optional top-level `sections` object (still `schema_version` 1): 14 named sections (`packages`, `apt_sources`, `kernel`, `restart_needed`, `kernel_surface`, `network`, `php`, `apache`, `site_dependencies`, `node_apps`, `docker`, `persistence`, `integrity`, `code_patterns`), each an envelope `{collected_at, sha256, error, source_errors, data}`. `error` is `null`, `timeout`, `not_installed`, `skipped_not_due`, `too_large` or a message (cut at 4000 characters); `data` is `null` whenever `error` is set; `sha256` is of the canonical JSON of `data`. Fidelity rule: `[]` means read and nothing found, `null` means not read (with a `source_errors` entry). `InventoryRequest` validates the envelope only and keeps `data` opaque; the per-section schemas are in `docs/superpowers/specs/2026-10-14-discover-design.md` §4.7, with a sample in `central/tests/Fixtures/snapshot.json`. The central answers `{"status": "ok"}` only: the agent takes no instruction from it.

## Agent architecture

`cli.py` (enroll/collect/run/status) → `snapshot.build_snapshot` runs collectors in isolation (an exception becomes an `errors[]` entry, the rest is still sent) → `transport.post_json` (HTTPS only, certificate verification cannot be disabled, optional SPKI pin parsed from DER without third-party libs).

Collectors: `server.py` (ip -j, machine-id), `apache.py` (vhosts from `apache2ctl -S` + parsing of `<VirtualHost>` blocks), `sites.py` (classifies php-fpm / php-mod / node / docker / proxy / static and resolves the system user via FPM pools or `ss` + `/proc`), `dbrefs.py` (DB name/user/host from `wp-config.php` / `.env*`), `mysql.py` (root over the Unix socket, or `/etc/discover-agent/mysql.cnf`), `techs.py` (CMS/frameworks and versions from version files, `composer.lock`, `package.json` + `node_modules/*/package.json`), `node.py` (every listening Node process, linked on the central to the `node` sites proxying to its port), `docker.py` (containers from the Engine API over `/var/run/docker.sock`, GET only, no env or non-Compose labels; a local `ProxyPass` to a published port makes a `docker` site, linked on the central like Node), `firewall.py` (`ufw status verbose` in the C locale: state, default policies, rules; snapshot key `ufw`).

Every system call goes through `util.run` or `util.run_full` (15 s timeout; sections pass longer ones where needed) and collectors take injectable `runner`/`reader` callables — tests use fakes, never the real system.

Security invariants (each has tests; keep them):
- `dbrefs.py` drops keys containing PASS/SECRET/KEY/TOKEN/SALT, cuts `DATABASE_URL` credentials at the last `@`, treats multi-line quoted dotenv values as one value, and drops refs that are not identifier-shaped.
- Files under site directories, and any path named by `Include` or `apache2ctl -S`, are user-controlled: read them with `util.read_regular_file` (regular files ≤ 1 MiB, non-blocking open), never `open()` directly.
- Config/credentials are written 0600 by `config.py`; `status` never prints the token.
- `docker.py` only sends GET requests and never keeps container env or labels other than `com.docker.compose.*` and `org.opencontainers.image.version`.

## Section architecture (agent 0.5)

`sections/core.py` runs the sections one after the other (daemon thread, 30 s; 300 s for the weekly ones `integrity` and `code_patterns`), builds the envelopes, and `enforce_caps` turns a section over 4 MiB, then the largest ones until the total is under 16 MiB, into `too_large`. One module per section in `sections/`, each with `collect(runner=..., reader=...) -> Collected(data, source_errors)` registered in `sections/__init__.py`; allow-lists (sysctl, ini keys, mitigation services, code patterns, Apache directives) live in `sections/defaults.py`. Sub-sources are read through `core.read_source`, which turns a failure into `source_errors[name]` and `None`. Real failures (message, `timeout`, `too_large`) are also added to `errors[]`; `not_installed` and `skipped_not_due` are not. Weekly sections have their own due date in `state.json` (`weekly_interval_days`, default 7; a failed result is retried after 24 h), written only after an HTTP 200; `last_attempt` is written on every run and an undelivered result waits 24 h after it; enrolment runs no weekly section. Sorted lists keep `sha256` stable: never put a live value (PID of a worker, counters, `Status` text) in `data`.

Section invariants (each has tests):
- Free text goes through `redact.py` (cron, sudoers, `ExecStart`, FPM and ini values, apt URIs, nft comments, `authorized_keys` comments) before it is sent. It is best effort: it reduces the risk, it does not remove it.
- No binary a server user controls is ever executed: only absolute system paths (`/usr/bin/phpX.Y`, `/usr/sbin/php-fpmX.Y`, version checked by regex); the Node version comes from the path or dpkg.
- PHP is run with `cwd=/` and a cleaned environment (`CLEAN_ENV`). Of `php -i` only the directive tables, PHP version, build date and the version lines of the `-m` modules are kept: never the `System` line nor the Environment and PHP Variables parts. All directives are reported on purpose (the central does not pilot the agent, so a narrow list would need a new agent per CVE): a credential under an unexpected name relies on `redact` alone. FPM pool files are reported whole, except `env[...]` lines, which never leave.
- Lock files (`composer.lock`, `package-lock.json`) are read with `util.read_regular_file` and the 8 MiB `techs.LOCK_LIMIT`; only name and version are kept. Everything else under a home or a webroot is limited to 1 MiB.
- `authorized_keys`: only type, fingerprint and masked comment leave; never `/etc/shadow`.
- Fail2Ban: configuration only (`fail2ban-client -d`), with action name, port and protocol alone since action options can hold API keys. Banned addresses are live values: dropped from the jail data, from `-A f2b-* -s <ip>` iptables rules and from the sets of `f2b-*` nft tables.

## Central architecture

- `POST /api/v1/enroll` (`EnrollController`): single-use enrollment token (SHA-256 stored, 24 h) → Sanctum token with ability `inventory:write` on the `Server` model (servers, not users, own API tokens). `config/sanctum.php` has `guard => []` so only bearer tokens authenticate the API.
- `POST /api/v1/inventory` → `IngestSnapshot`: stores the snapshot, then **replaces** the server's current state (ips, sites, site_db_refs, db_databases, db_users, db_grants) in one transaction, then runs `LinkDatabases` for the **whole fleet** (a ref may point at another server's database).
- `LinkDatabases`: loopback/socket/own address → local; address of another *active* server → remote; else unresolved. Addresses are self-declared by agents, so an address claimed by two servers is ignored (anti-hijack) — keep that rule.
- Read API (`GET /api/v1/servers…`, `ServerReadController`): for external applications, tokens on the `ApiClient` model with ability `inventory:read`, created in the admin. Keep these rules, each has tests: `EnsureApiClientIsAllowed` refuses any address outside `allowed_ips` and an empty list refuses everyone; agent tokens never get this ability, so a compromised server cannot read the fleet; responses list their fields one by one, so a new column or the admin's notes do not leave by accident. The check relies on `$request->ip()`: only proxies named in `DISCOVER_TRUSTED_PROXIES` are believed (`App\Support\TrustedProxyList` drops `*` and wide ranges), never trust everyone. `docs/api/` (OpenAPI and Postman, given to consumers) must follow any change to these routes or fields.
- Table `db_databases` (model `Database`): `databases` is reserved in MySQL.
- `IngestSnapshot` also stores `sections` in `server_sections` (history: a new row only when `sha256`, `error` or `source_errors` changed, otherwise `collected_at`/`last_seen_at`; `skipped_not_due` writes nothing); `snapshots.payload` does not hold them. `LimitRequestBody` caps the body at 20 MiB. Deploy the central before an agent 0.5, or `sections` is dropped silently.
- Filament: resources in `app/Filament/Resources/{Servers,Sites,Databases}` (Sites and Databases are read-only), dashboard widgets in `app/Filament/Widgets`. The server's « Pare-feu » tab holds one table per firewall: UFW (relation manager), then nftables, iptables, iptables-legacy and Fail2Ban (`App\Livewire\Firewall`, Filament tables over the rows of the latest `network` section, built by `App\Support\FirewallEngines`, `NftRuleset` and `IptablesRules`). Enrollment tokens are shown once, in the second step of the generation modal (`GenerateEnrollmentTokenAction`). `Pages\Commands` (« Commandes ») lists the agent's install/update/run/check/remove commands, built from `app.url` and `discover.agent_install_url` (tokens stay `<jeton>` placeholders); its « Central : maintenance » group is shown to admins only. Keep it in step with `docs/install-agent.md`.

- Server slug: `servers.slug` (unique, generated by `App\Support\ServerSlug` in the `Server` `creating` hook) is the reference identifier for every external tool (Ansible, CVE tracker). `slug_locked_at` is set at a new server's first inventory (`IngestSnapshot`) or, for servers that already reported, by the admin action « Figer les slugs » (`LockSlugsAction` on ListServers); once locked the slug is never modified. A reinstall regenerates a token on the existing server (`CreateEnrollmentToken` reuses the record, a revoked one goes back to pending), so the slug survives: never create a second server for a reinstall.
- `servers.connect_address` / `connect_port`: manual SSH address and port, editable by admins only; `Server::effectiveAddress()` = `connect_address`, else `primary_ip`.
- `site_domains` (model `SiteDomain`) is derived from Apache ServerName + aliases (lowercased) and replaced at each inventory. Read API additions: `GET /api/v1/servers/{server}` takes the numeric id or the slug (`Server::findByKey`), `GET /api/v1/domains` (`DomainReadController`), `GET /api/v1/inventory/ansible` (`AnsibleInventoryController`, dynamic inventory; script `docs/ansible/stackcard_inventory.py`, guide `docs/ansible.md`). Admin page « Domaines » (`app/Filament/Resources/Domains`, read-only, all roles).
- Roles: `users.role` is `admin` or `user`. Admins alone reach the « Réglages » group (`UserResource`, `ApiClientResource`, `Pages\Settings`) and enrollment tokens, through `UserPolicy`, `ApiClientPolicy` and `isAdmin()`. A new sensitive screen needs the same check. An admin cannot delete itself or change its own role, so one always remains.
- Dates: stored and returned by the API in UTC, so `config('app.timezone')` stays `UTC`. `App\Support\DisplaySettings` (table `settings`) holds the site-wide display timezone and date format, applied in `AppServiceProvider`: use `->dateTime()` or `->date()` without a format, and `DisplaySettings::formatDateTime()` for text built by hand.

## SQL release scripts (`_release/`)

Every schema change (new Laravel migration) also gets a SQL file in `_release/` named `YYYY-MM-DD_NNN.sql` (NNN restarts at `001` each day and increments for the same date). It holds the DDL from `php artisan migrate --pretend` plus the matching `INSERT INTO migrations` row. `2026-10-24_001.sql` is the full initial schema. See `_release/README.md`.

Local dev runs on MAMP PRO: `https://stackcard.larssen-homelab.test` (document root `central/public`), MySQL 8.0 database `scanner` on 127.0.0.1:3306 (not `localhost`: Homebrew PHP would look for a non-existent socket). Tests still use SQLite in memory.

## Testing gotchas (central)

- Pest feature tests use `RefreshDatabase` (see `tests/Pest.php`, which also provides `snapshotFixture()` and `enrolledServer()`).
- Read API test helpers also live in `tests/Pest.php`: `readApi()`, `readClient()`, `adminUser()`, `plainUser()`.
- Laravel keeps the authenticated Sanctum user between requests inside one test: call `app('auth')->forgetGuards()` before a request made with a different token, and use `actingAs($user, 'web')` for Filament tests.
- Order queries explicitly (`orderBy('id')`) before asserting lists; SQLite may return index order.

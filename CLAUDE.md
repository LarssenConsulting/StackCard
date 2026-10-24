# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Discover inventories Ubuntu 24.04 servers (name, IPs, Apache sites, MySQL databases, the system and DB users tied to each site) and centralises them. Two independent apps share one contract:

- `agent/` — Python agent, **standard library only** (runs as root on servers; no pip installs). Push-only: no listening port, never accepts commands.
- `central/` — Laravel 13 + Filament 5 + Sanctum (PHP ≥ 8.3), receives snapshots and shows them.
- `docs/superpowers/specs/2026-10-14-discover-design.md` is the design authority; user-facing docs are in `docs/install-*.md` (French). UI strings and agent messages are French.

## Commands

```bash
# Agent (run from agent/)
python3 -m unittest                                   # all tests
python3 -m unittest tests.test_apache.VhostTest.test_https_php_fpm_block   # one test
python3 packaging/build_deb.py                        # -> agent/dist/discover-agent_<ver>_all.deb (pure Python, no dpkg needed)
python3 packaging/build_release.py                    # -> agent/dist/release/agent/ (install.sh, latest .deb + .sha256); CI publishes it as a GitHub release on tag agent-v<version>
sh integration/run.sh                                 # Ubuntu 24.04 end-to-end check, needs Docker

# Central (run from central/)
./vendor/bin/pest < /dev/null                         # all tests (SQLite in memory)
./vendor/bin/pest --filter="links a site"             # one test
php artisan serve                                     # /admin (MFA is required on first login)
php artisan make:filament-user                        # create an admin
php artisan discover:prune-snapshots                  # retention (also scheduled daily 03:00)
```

Avoid interactive commands (e.g. `pest --init`): redirect stdin from `/dev/null`.

## The snapshot contract (schema_version 1)

The agent's JSON is built in `agent/discover_agent/snapshot.py` from the collectors; the central validates it in `central/app/Http/Requests/InventoryRequest.php` and stores only the validated subset. Any field change must touch both sides, `central/tests/Fixtures/snapshot.json`, and bump `schema_version` if it is not backward compatible. Site keys come from `collectors/sites.py::_empty_site` plus `db_refs`.

## Agent architecture

`cli.py` (enroll/collect/run/status) → `snapshot.build_snapshot` runs collectors in isolation (an exception becomes an `errors[]` entry, the rest is still sent) → `transport.post_json` (HTTPS only, certificate verification cannot be disabled, optional SPKI pin parsed from DER without third-party libs).

Collectors: `server.py` (ip -j, machine-id), `apache.py` (vhosts from `apache2ctl -S` + parsing of `<VirtualHost>` blocks), `sites.py` (classifies php-fpm / php-mod / node / proxy / static and resolves the system user via FPM pools or `ss` + `/proc`), `dbrefs.py` (DB name/user/host from `wp-config.php` / `.env*`), `mysql.py` (root over the Unix socket, or `/etc/discover-agent/mysql.cnf`).

Every system call goes through `util.run` (15 s timeout) and collectors take injectable `runner`/`reader` callables — tests use fakes, never the real system.

Security invariants (each has tests; keep them):
- `dbrefs.py` drops keys containing PASS/SECRET/KEY/TOKEN/SALT, cuts `DATABASE_URL` credentials at the last `@`, treats multi-line quoted dotenv values as one value, and drops refs that are not identifier-shaped.
- Files under site directories are user-controlled: read them with `util.read_regular_file` (regular files ≤ 1 MiB, non-blocking open), never `open()` directly.
- Config/credentials are written 0600 by `config.py`; `status` never prints the token.

## Central architecture

- `POST /api/v1/enroll` (`EnrollController`): single-use enrollment token (SHA-256 stored, 24 h) → Sanctum token with ability `inventory:write` on the `Server` model (servers, not users, own API tokens). `config/sanctum.php` has `guard => []` so only bearer tokens authenticate the API.
- `POST /api/v1/inventory` → `IngestSnapshot`: stores the snapshot, then **replaces** the server's current state (ips, sites, site_db_refs, db_databases, db_users, db_grants) in one transaction, then runs `LinkDatabases` for the **whole fleet** (a ref may point at another server's database).
- `LinkDatabases`: loopback/socket/own address → local; address of another *active* server → remote; else unresolved. Addresses are self-declared by agents, so an address claimed by two servers is ignored (anti-hijack) — keep that rule.
- Table `db_databases` (model `Database`): `databases` is reserved in MySQL.
- Filament: resources in `app/Filament/Resources/{Servers,Sites,Databases}` (Sites and Databases are read-only), dashboard widgets in `app/Filament/Widgets`. Enrollment tokens are shown once in a notification.

## SQL release scripts (`_release/`)

Every schema change (new Laravel migration) also gets a SQL file in `_release/` named `YYYY-MM-DD_NNN.sql` (NNN restarts at `001` each day and increments for the same date). It holds the DDL from `php artisan migrate --pretend` plus the matching `INSERT INTO migrations` row. `2026-10-24_001.sql` is the full initial schema. See `_release/README.md`.

Local dev runs on MAMP PRO: `https://scanner.larssen-homelab.test` (document root `central/public`), MySQL 8.0 database `scanner` on 127.0.0.1:3306 (not `localhost`: Homebrew PHP would look for a non-existent socket). Tests still use SQLite in memory.

## Testing gotchas (central)

- Pest feature tests use `RefreshDatabase` (see `tests/Pest.php`, which also provides `snapshotFixture()` and `enrolledServer()`).
- Laravel keeps the authenticated Sanctum user between requests inside one test: call `app('auth')->forgetGuards()` before a request made with a different token, and use `actingAs($user, 'web')` for Filament tests.
- Order queries explicitly (`orderBy('id')`) before asserting lists; SQLite may return index order.

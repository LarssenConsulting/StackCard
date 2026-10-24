# Discover

Inventaire centralisé d'un parc de serveurs Ubuntu : pour chaque serveur, son nom et ses IP, les
sites servis par Apache (PHP et JavaScript en reverse proxy), les bases MySQL/MariaDB, et les
utilisateurs liés à chaque site (utilisateur système et compte de base de données).

```
[Serveur Ubuntu 24.04]                              [Central]
discover-agent (Python, root, lecture seule) ─HTTPS─▶ Laravel + Filament + MySQL
  timer systemd toutes les heures                    POST /api/v1/inventory
  snapshot JSON (schema v1)                          Bearer <jeton propre au serveur>
```

- **`agent/`** — l'agent : collecteurs (serveur, Apache, sites, bases référencées, MySQL),
  envoi HTTPS avec vérification de certificat obligatoire et épinglage optionnel, paquet `.deb`.
- **`central/`** — l'application centrale : API d'enrôlement et d'inventaire, mise en relation
  site → base (y compris d'un serveur à l'autre), interface d'administration avec double
  authentification obligatoire.
- **`docs/`** — [installer le central](docs/install-central.md),
  [installer l'agent](docs/install-agent.md), la [spécification](docs/superpowers/specs/2026-10-14-discover-design.md)
  et le [plan d'implémentation](docs/superpowers/plans/2026-10-14-discover-v1.md).

## Sécurité en bref

- L'agent n'ouvre aucun port ; le central ne peut lui envoyer aucune commande.
- Enrôlement par jeton à usage unique (24 h) ; chaque serveur a ensuite son propre jeton,
  stocké haché côté central, révocable depuis l'interface.
- Aucun mot de passe, empreinte ou clé ne quitte les serveurs : seuls les noms de bases,
  d'utilisateurs et d'hôtes sont transmis.
- L'API valide strictement le format, limite la taille (5 Mo) et le débit (30 envois/min/jeton,
  10 tentatives d'enrôlement/min/IP).

## Développement

```bash
cd agent && python3 -m unittest            # tests de l'agent (aucune dépendance)
python3 agent/packaging/build_deb.py       # construit agent/dist/discover-agent_<version>_all.deb
python3 agent/packaging/build_release.py   # fichiers d'une release (install.sh + .deb), publiés par la CI sur un tag agent-v<version>
sh agent/integration/run.sh                # test d'intégration Ubuntu 24.04 (Docker requis)

cd central && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan make:filament-user
./vendor/bin/pest                          # tests du central
php artisan serve                          # http://localhost:8000/admin
```

## Hors périmètre de la v1

Autres distributions, Nginx, PostgreSQL/MongoDB, alertes e-mail, historique des changements,
mTLS, rôles multiples.

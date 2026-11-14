# StackCard

Inventaire centralisé et source de vérité d'un parc de serveurs Ubuntu (22.04, 24.04, 26.04). Un agent
installé sur chaque serveur envoie, toutes les heures, l'état de la machine : identité et adresses, sites
Apache et leurs domaines, technologies (PHP, Node.js, Docker), bases MySQL/MariaDB et leurs utilisateurs,
pare-feu, paquets et **mises à jour APT en attente**. Le central les rassemble, les affiche et les expose
par API : à Ansible (inventaire dynamique pour Semaphore), à un outil de suivi des CVE, ou à tout autre
consommateur.

```
[Serveurs Ubuntu]                        [Central StackCard]                     [Consommateurs]
discover-agent (Python, root,  ─HTTPS─▶  Laravel + Filament + MySQL  ◀─HTTPS─   Semaphore / Ansible
lecture seule, timer horaire)            admin, API de lecture, wiki             outil CVE, scripts
        ▲                                                                              │
        └──────────────────────────── SSH (Ansible, depuis Semaphore) ─────────────────┘
```

## Ce que fait StackCard

- **Inventaire** : chaque serveur, ses IP, ses sites et domaines, ses applications Node.js et conteneurs
  Docker, ses bases et comptes MySQL, son pare-feu (UFW, nftables, iptables, Fail2Ban), et des sections
  détaillées (paquets, noyau, PHP, Apache, persistance, intégrité…).
- **Identifiant stable** : chaque serveur a un **slug** figé, la référence commune de tous les outils.
- **Mises à jour** : paquets en attente, correctifs de sécurité, redémarrage requis ; vue par serveur et
  par paquet, avec un état de fraîcheur (à jour, périmé, inconnu).
- **API de lecture** : serveurs, domaines, mises à jour, versions de paquets, inventaire Ansible.
- **Ansible / Semaphore** : inventaire dynamique (hôtes = slugs, groupes automatiques comme
  `ubuntu_24_04`, `security_updates` ou `reboot_required`) ; l'adresse SSH n'est utilisée qu'une fois validée.
- **Wiki** : un wiki d'exploitation, avec schémas et procédures, consultable sans connexion à
  `https://<votre-central>/wiki`.

## Le dépôt

- **`agent/`** : l'agent (Python, bibliothèque standard uniquement), ses collecteurs et sections, le
  paquet `.deb` et le script d'installation, publiés par la CI sur un tag `agent-v<version>`.
- **`central/`** : l'application centrale (Laravel 13, Filament 5, Sanctum) : enrôlement, réception des
  inventaires, interface d'administration (double authentification obligatoire, rôles admin et
  utilisateur), API de lecture, wiki.
- **`docs/`** : [installer le central](docs/install-central.md), [installer l'agent](docs/install-agent.md),
  [Ansible et Semaphore](docs/ansible.md) (script d'inventaire dans [`docs/ansible/`](docs/ansible/)),
  l'[API de lecture](docs/api/) (OpenAPI et Postman) et les spécifications dans
  [`docs/superpowers/specs/`](docs/superpowers/specs/).
- **`_release/`** : scripts SQL de mise à jour du central, pour les installations qui ne lancent pas les
  migrations.

## Sécurité en bref

- L'agent n'ouvre aucun port et ne reçoit jamais d'ordre du central : il envoie, c'est tout.
- Enrôlement par jeton à usage unique (24 h) ; chaque serveur a ensuite son propre jeton, stocké haché,
  révocable depuis l'interface.
- Aucun mot de passe, clé ni secret ne quitte les serveurs ; les textes libres sont masqués avant envoi.
- Pour les mises à jour, l'agent ne lance que `apt-get update` (au plus toutes les `apt_refresh_hours`
  heures) et des simulations : il n'installe jamais rien et n'attend jamais un verrou APT.
- L'API de lecture exige un jeton de client et une adresse IP autorisée, et ne publie que des champs
  listés un à un. Les agents ne peuvent pas lire le parc.
- L'inventaire Ansible n'utilise une adresse remontée par un agent qu'après validation par un admin.

## Développement

```bash
cd agent && python3 -m unittest            # tests de l'agent (aucune dépendance)
python3 agent/packaging/build_deb.py       # construit agent/dist/discover-agent_<version>_all.deb
python3 agent/packaging/build_release.py   # fichiers d'une release (install.sh + .deb)
sh agent/integration/run.sh                # test d'intégration Ubuntu 24.04 (Docker requis)

cd central && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan make:filament-user
./vendor/bin/pest < /dev/null              # tests du central
php artisan serve                          # http://localhost:8000/admin, wiki sur /wiki
```

Les identifiants techniques gardent leur nom d'origine, pour ne pas casser les installations existantes :
paquet et commande `discover-agent`, dossiers `/etc/discover-agent` et `/var/lib/discover-agent`, variables
`DISCOVER_*`.

## Hors périmètre

Autres distributions que Ubuntu, Nginx, PostgreSQL/MongoDB, alertes e-mail, mTLS. StackCard observe : il
n'applique aucune modification aux serveurs (c'est le rôle d'Ansible).

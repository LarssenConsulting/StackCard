# Installer l'agent Discover

L'agent tourne sur chaque serveur Ubuntu 22.04, 24.04 ou 26.04. Il est en Python (bibliothèque standard
uniquement), lit la configuration du serveur **sans rien modifier**, et envoie un inventaire au
central toutes les heures en HTTPS. Il n'ouvre aucun port et n'accepte aucune commande du central.

## 1. Publier une version

Le dépôt public [LarssenConsulting/StackCard](https://github.com/LarssenConsulting/StackCard) publie
l'agent par GitHub Actions (`.github/workflows/ci.yml`) : les tests tournent à chaque push, et un
tag `agent-v<version>` crée une **release GitHub** avec `install.sh`, `discover-agent_<version>_all.deb`,
`discover-agent_latest_all.deb`, son `.sha256` et `version.txt` (lu par le central pour signaler
les agents à mettre à jour). Pour publier :

```bash
# 1. augmenter __version__ dans agent/discover_agent/__init__.py, commiter, pousser
git tag agent-v0.3.1          # doit être égal à __version__, sinon la CI refuse
git push origin agent-v0.3.1
```

La release devient « latest » : `install.sh` et le paquet sont téléchargés depuis
`https://github.com/LarssenConsulting/StackCard/releases/latest/download/`, donc toujours de la
même version. Pour construire les mêmes fichiers en local : `python3 agent/packaging/build_release.py`
(→ `agent/dist/release/agent/`). Pour les héberger ailleurs : lancez le script avec
`DISCOVER_DOWNLOAD_BASE=https://…` et réglez `DISCOVER_AGENT_INSTALL_URL` dans le `.env` du central.

## 2. Installer et enrôler un serveur

1. Dans le central : **Serveurs → Nouveau**, saisir un nom, puis sur la fiche du serveur
   **Générer un jeton d'enrôlement**. La fenêtre affiche **une seule fois** la commande,
   l'adresse du central et le jeton (valable 24 h, à usage unique).
2. Sur le serveur :

```bash
curl -fsSL https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh | sudo sh
```

Le script télécharge le paquet, vérifie son empreinte SHA-256, l'installe (dépendances
`python3 (>= 3.10)`, `iproute2`, `openssl` comprises), puis demande :

- **l'adresse (FQDN) du central**, par exemple `sentinel.larssen-homelab.fr`. Il vérifie que
  `https://<fqdn>/up` répond avec un certificat valide avant d'aller plus loin : un certificat
  reconnu par le serveur est obligatoire (aucune option pour s'en passer) ;
- **le jeton**, saisi sans écho. Il est transmis à l'agent par l'entrée standard, jamais en
  argument (invisible dans `ps` et l'historique). Un jeton refusé est redemandé (3 essais).

L'enrôlement écrit `/etc/discover-agent/agent.conf` et `/etc/discover-agent/credentials`
(root, 0600), active le timer systemd et envoie un premier inventaire.
Le serveur doit pouvoir joindre le central en HTTPS (port 443 sortant).

**Relancer le script** sur un serveur déjà installé met l'agent à jour si une version plus récente
est publiée, puis demande « Mettre à jour le jeton ? » : oui → nouvelle adresse (l'actuelle est
proposée par défaut) et nouveau jeton ; non → configuration inchangée.

**Sans questions** (Ansible, plusieurs serveurs) :

```bash
curl -fsSL https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh \
  | sudo DISCOVER_URL=sentinel.larssen-homelab.fr DISCOVER_TOKEN=<jeton> sh
```

Sans terminal ni `DISCOVER_TOKEN`, un serveur déjà enrôlé est seulement mis à jour.

## 3. Enrôlement manuel (CA interne, épinglage)

Le paquet seul (`python3 agent/packaging/build_deb.py` → `agent/dist/discover-agent_<version>_all.deb`,
puis `sudo apt install ./discover-agent_<version>_all.deb`) s'enrôle avec la commande « Agent déjà
installé » de la fenêtre du jeton, qui accepte aussi :

```bash
sudo discover-agent enroll --url https://sentinel.larssen-homelab.fr --token <jeton>
# Autorité de certification interne :   --ca-file /usr/local/share/ca-certificates/ca-interne.crt
# Épinglage de la clé du central :     --pin-sha256 <empreinte>   (voir install-central.md §7)
# Jeton lu sur l'entrée standard :     --token -
```

## 4. Vérifier

```bash
sudo discover-agent status           # dernier envoi, dernière erreur, erreurs de collecte
sudo discover-agent collect | less   # exactement ce qui serait envoyé, sans l'envoyer
systemctl list-timers discover-agent.timer
journalctl -u discover-agent.service
```

## Ce que l'agent lit (et ne lit pas)

| Source | Usage |
|---|---|
| `apache2ctl -S`, `/etc/apache2/sites-enabled/*` | vhosts actifs, domaines, ports, HTTPS, DocumentRoot, ProxyPass, handler PHP-FPM |
| `/etc/php/*/fpm/pool.d/*.conf` | utilisateur et version PHP des pools |
| `ss -ltnp`, `/proc/<pid>` | processus Node derrière un `ProxyPass`, son utilisateur, son dossier, systemd/PM2 |
| `ufw status verbose` | état du pare-feu, politiques par défaut, règles (destination, action, source, IPv4/IPv6, commentaire) |
| API Docker (`/var/run/docker.sock`, requêtes `GET` uniquement) | conteneurs, image, état, ports publiés, projet/service Compose, politique de redémarrage — jamais les variables d'environnement ni les autres labels |
| `wp-config.php`, `.env`, `.env.local`, `.env.production` | **nom** de la base, **utilisateur**, **hôte** — jamais les mots de passe |
| MySQL/MariaDB (socket local) | bases, tailles, comptes `user@host`, droits — jamais les empreintes de mot de passe |

Les clés de configuration contenant `PASS`, `SECRET`, `KEY`, `TOKEN` ou `SALT` sont ignorées dès
la lecture ; le mot de passe d'une `DATABASE_URL` est retiré ; seuls des fichiers réguliers de
moins de 1 Mo sont lus.

## Sections détaillées (agent 0.5)

Depuis la 0.5, l'agent envoie aussi 14 sections d'état (paquets, dépôts apt, noyau, services à
redémarrer, réseau, PHP, Apache, dépendances des sites, applications Node, Docker, persistance,
intégrité, motifs de code). Elles sont affichées dans l'onglet « État détaillé » du serveur. **Déployez
d'abord le central** (voir `install-central.md`), dont la limite de corps doit être de 20 Mo.

**Outils optionnels.** L'agent n'installe rien : `debsums` (section `integrity`), `needrestart`
(`restart_needed`) et `nftables` (`network.nft`) sont facultatifs. Sans eux, la section vaut
`not_installed` (`debsums`, `needrestart`) ou son entrée `source_errors.nft` l'indique (`nftables`).
Cette absence n'apparaît pas dans `discover-agent status` : elle se voit dans l'onglet « État détaillé »
du central (`error: not_installed` ou `source_errors`). Le paquet les suggère
(`Suggests`) sans les installer : `sudo apt install debsums needrestart nftables`. Installez une version corrigée
de `needrestart` (famille CVE-2024-48990) : les versions vulnérables exécutent, en root, du code contrôlé
par un utilisateur local, et l'agent lance `needrestart` en root.

**Rythme.** Les sections `integrity` et `code_patterns` sont lentes (jusqu'à 5 min) : elles tournent
une fois par semaine, pas toutes les heures. Réglez l'intervalle dans `/etc/discover-agent/agent.conf` :

```ini
[collect]
weekly_interval_days = 7
```

Elles n'arrivent pas avec l'enrôlement : le premier passage du timer les exécute. Un résultat en échec (délai dépassé, erreur) est retenté après 24 h, tout comme un résultat qui n'a pas pu être envoyé ; un résultat `not_installed` attend en revanche l'intervalle complet. `discover-agent collect` n'exécute
pas ces sections ; `discover-agent collect --full` les exécute (lent) sans rien envoyer ni modifier
l'état.

**Lancement manuel.** `sudo discover-agent run` ne passe pas par le service systemd : il n'a pas son bac à
sable. Les données `livepatch` (`kernel`), `modprobe` (`kernel_surface`) et `integrity` peuvent donc différer
d'un passage du timer. C'est normal : l'historique peut en garder une ligne, qui ne signale aucun changement sur le
serveur. Pour reproduire ce que voit le timer, lancez `sudo systemctl start discover-agent`.

**Intégrité et modules du noyau.** Le service masque `/lib/modules` (`ProtectKernelModules=yes`). Dans ce cas
`modules_checked` vaut `false` et `missing` compte les modules : un module modifié n'apparaît pas dans `changed`.

**Données collectées.** Voir `docs/superpowers/specs/2026-10-14-discover-design.md` §4.7 : en
particulier les lignes de cron et de sudoers, les `ExecStart` des unités hors paquet, les valeurs de
pools PHP-FPM, toutes les directives PHP (de `php -i` pour FPM et CLI, de `php.ini` et `conf.d` pour mod_php), les extensions PHP chargées et les versions des bibliothèques liées, les URI des dépôts apt, la liste des paquets, les règles de pare-feu (UFW, nftables, iptables), les ports
en écoute, et pour `authorized_keys` uniquement l'empreinte SHA-256, le type et le commentaire (jamais la clé).
L'agent ne lit jamais `/etc/shadow` ni les identifiants des runners GitHub.

**Masquage et sa limite.** Toute valeur libre passe par un masquage qui remplace par `***` les
options `-p…`/`--password=…`, `user:motdepasse@` et `jeton@` dans une URL http(s) ou ftp, `curl -u`, `redis-cli -a`, `docker login -p`, `auth=…`, les valeurs des clés contenant
`PASS`, `SECRET`, `KEY`, `TOKEN` ou `SALT`, les hachages de mots de passe et les clés SSH collées. Il
reconnaît des formes connues : un secret écrit autrement peut passer. Vérifiez
`discover-agent collect` avant d'enrôler un serveur sensible.

## MySQL : compte en lecture seule (si root n'est pas accessible par socket)

Par défaut l'agent se connecte en root par le socket Unix (comportement standard d'Ubuntu, aucun
mot de passe à stocker). Si root a un mot de passe, l'agent signale
`MySQL inaccessible (...)` ; créez alors un compte dédié :

```sql
CREATE USER 'discover'@'localhost' IDENTIFIED BY '<mot de passe fort>';
-- liste des bases ; lecture des comptes, des droits et des tailles (information_schema)
GRANT SHOW DATABASES ON *.* TO 'discover'@'localhost';
-- MySQL ne compte que les tables que le compte peut lire : sans ce droit, toutes les tailles valent 0
GRANT SELECT ON *.* TO 'discover'@'localhost';
```

```bash
sudo install -m 0600 -o root -g root /dev/stdin /etc/discover-agent/mysql.cnf <<'EOF'
[client]
user=discover
password="<mot de passe fort>"
EOF
```

Gardez les guillemets autour du mot de passe : dans ce fichier, `#` commence un commentaire même
en milieu de ligne, et un mot de passe qui en contient serait lu tronqué (« Access denied … using
password: YES »). Évitez `"` et `\`, interprétés même entre guillemets ; le plus simple est un mot
de passe alphanumérique, par exemple `openssl rand -base64 24 | tr -dc 'A-Za-z0-9'`.

## Dépannage

| Symptôme | Cause / solution |
|---|---|
| `HTTP 401` | Serveur révoqué ou jeton remplacé : générer un nouveau jeton et relancer `enroll`. |
| `HTTP 410` à l'enrôlement | Jeton expiré (24 h) ou déjà utilisé : en générer un nouveau. |
| `HTTP 409` à l'enrôlement | Ce `machine-id` est déjà enrôlé sous un autre nom : supprimer ou révoquer l'ancien serveur. |
| `CERTIFICATE_VERIFY_FAILED` | Certificat du central non reconnu : passer `--ca-file` à `enroll`. |
| `PinMismatch` | La clé du central a changé : réenrôler avec la nouvelle empreinte. |
| Site « analyse partielle » | Le vhost utilise des `${VARIABLES}` ou `Use Macro` : valeurs non résolues, non devinées. |

## Désinstaller

```bash
sudo apt remove discover-agent   # garde /etc/discover-agent
sudo apt purge discover-agent    # supprime aussi la configuration et l'état
```

## Test d'intégration (développeurs)

`agent/integration/run.sh` construit une image Docker Ubuntu 24.04 avec Apache, PHP-FPM, Node et
MariaDB, installe le paquet et vérifie l'inventaire produit (types de sites, utilisateurs, bases,
droits, absence de secrets). Nécessite Docker.

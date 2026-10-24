# Installer l'agent Discover

L'agent tourne sur chaque serveur Ubuntu 24.04. Il est en Python (bibliothèque standard
uniquement), lit la configuration du serveur **sans rien modifier**, et envoie un inventaire au
central toutes les heures en HTTPS. Il n'ouvre aucun port et n'accepte aucune commande du central.

## 1. Publier une version

Le dépôt public [LarssenConsulting/StackCard](https://github.com/LarssenConsulting/StackCard) publie
l'agent par GitHub Actions (`.github/workflows/ci.yml`) : les tests tournent à chaque push, et un
tag `agent-v<version>` crée une **release GitHub** avec `install.sh`, `discover-agent_<version>_all.deb`,
`discover-agent_latest_all.deb` et son `.sha256`. Pour publier :

```bash
# 1. augmenter __version__ dans agent/discover_agent/__init__.py, commiter, pousser
git tag agent-v0.2.0          # doit être égal à __version__, sinon la CI refuse
git push origin agent-v0.2.0
```

La release devient « latest » : `install.sh` et le paquet sont téléchargés depuis
`https://github.com/LarssenConsulting/StackCard/releases/latest/download/`, donc toujours de la
même version. Pour construire les mêmes fichiers en local : `python3 agent/packaging/build_release.py`
(→ `agent/dist/release/agent/`). Pour les héberger ailleurs : lancez le script avec
`DISCOVER_DOWNLOAD_BASE=https://…` et réglez `DISCOVER_AGENT_INSTALL_URL` dans le `.env` du central.

## 2. Installer et enrôler un serveur

1. Dans le central : **Serveurs → Nouveau**, saisir un nom, puis sur la fiche du serveur
   **Générer un jeton d'enrôlement**. La notification affiche **une seule fois** la commande,
   l'adresse du central et le jeton (valable 24 h, à usage unique).
2. Sur le serveur :

```bash
curl -fsSL https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh | sudo sh
```

Le script télécharge le paquet, vérifie son empreinte SHA-256, l'installe (dépendances
`python3 (>= 3.12)`, `iproute2`, `openssl` comprises), puis demande :

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
installé » de la notification, qui accepte aussi :

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
| `wp-config.php`, `.env`, `.env.local`, `.env.production` | **nom** de la base, **utilisateur**, **hôte** — jamais les mots de passe |
| MySQL/MariaDB (socket local) | bases, tailles, comptes `user@host`, droits — jamais les empreintes de mot de passe |

Les clés de configuration contenant `PASS`, `SECRET`, `KEY`, `TOKEN` ou `SALT` sont ignorées dès
la lecture ; le mot de passe d'une `DATABASE_URL` est retiré ; seuls des fichiers réguliers de
moins de 1 Mo sont lus.

## MySQL : compte en lecture seule (si root n'est pas accessible par socket)

Par défaut l'agent se connecte en root par le socket Unix (comportement standard d'Ubuntu, aucun
mot de passe à stocker). Si root a un mot de passe, l'agent signale
`MySQL inaccessible (...)` ; créez alors un compte dédié :

```sql
CREATE USER 'discover'@'localhost' IDENTIFIED BY '<mot de passe fort>';
-- liste des bases + lecture des comptes et des droits (information_schema)
GRANT SHOW DATABASES ON *.* TO 'discover'@'localhost';
GRANT SELECT ON mysql.* TO 'discover'@'localhost';
-- facultatif : nécessaire pour connaître la taille des bases (sinon taille 0)
-- GRANT SELECT ON *.* TO 'discover'@'localhost';
```

```bash
sudo install -m 0600 -o root -g root /dev/stdin /etc/discover-agent/mysql.cnf <<'EOF'
[client]
user=discover
password=<mot de passe fort>
EOF
```

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

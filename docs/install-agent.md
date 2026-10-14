# Installer l'agent Discover

L'agent tourne sur chaque serveur Ubuntu 24.04. Il est en Python (bibliothèque standard
uniquement), lit la configuration du serveur **sans rien modifier**, et envoie un inventaire au
central toutes les heures en HTTPS. Il n'ouvre aucun port et n'accepte aucune commande du central.

## 1. Construire le paquet

Sur n'importe quelle machine avec Python ≥ 3.12 (macOS compris) :

```bash
python3 agent/packaging/build_deb.py
# -> agent/dist/discover-agent_0.1.0_all.deb
```

## 2. Installer

```bash
scp agent/dist/discover-agent_0.1.0_all.deb serveur:/tmp/
ssh serveur 'sudo apt install /tmp/discover-agent_0.1.0_all.deb'
```

Dépendances installées automatiquement : `python3 (>= 3.12)`, `iproute2`, `openssl`.
Le serveur doit pouvoir joindre le central en HTTPS (port 443 sortant).

## 3. Enrôler

1. Dans le central : **Serveurs → Nouveau**, saisir un nom, puis sur la fiche du serveur
   **Générer un jeton d'enrôlement**. La commande s'affiche **une seule fois** (jeton valable 24 h).
2. Sur le serveur, lancer la commande affichée :

```bash
sudo discover-agent enroll --url https://discover.example.lan --token <jeton>
# Autorité de certification interne :   --ca-file /usr/local/share/ca-certificates/ca-interne.crt
# Épinglage de la clé du central :     --pin-sha256 <empreinte>   (voir install-central.md §7)
```

L'enrôlement écrit `/etc/discover-agent/agent.conf` et `/etc/discover-agent/credentials`
(root, 0600), active le timer systemd et envoie un premier inventaire.

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

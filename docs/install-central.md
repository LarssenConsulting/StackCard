# Installer le central Discover

Le central est une application Laravel 13 + Filament 5. Il reçoit les inventaires des agents
(`POST /api/v1/inventory`) et les affiche dans une interface d'administration (`/admin`).

Cette procédure vise un serveur Ubuntu 24.04 avec Apache, PHP-FPM et MySQL/MariaDB. Le central
peut être hébergé sur l'un des serveurs inventoriés (qui aura alors aussi son agent).

## 1. Prérequis

Les dépendances (Symfony 8) exigent **PHP ≥ 8.4.1** ; Ubuntu 24.04 ne fournit que la 8.3, d'où le
dépôt `ppa:ondrej/php`.

```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
sudo apt install apache2 mariadb-server git unzip composer \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl \
  php8.4-zip php8.4-intl php8.4-bcmath php8.4-sqlite3
```

## 2. Base de données

```sql
CREATE DATABASE discover CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'discover_app'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT ALL PRIVILEGES ON discover.* TO 'discover_app'@'localhost';
```

## 3. Code et configuration

```bash
sudo git clone https://github.com/LarssenConsulting/StackCard.git /var/www/discover
sudo chown -R $USER /var/www/discover
cd /var/www/discover/central
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Dans `.env` :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://discover.example.lan   # l'URL exacte que les agents utiliseront
APP_LOCALE=fr

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=discover
DB_USERNAME=discover_app
DB_PASSWORD=<mot de passe fort>
```

`APP_URL` sert aussi à construire l'adresse du central et la commande `discover-agent enroll`
affichées avec chaque jeton d'enrôlement. Le script d'installation de l'agent est pris par défaut dans la
dernière release GitHub (`https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh`) ;
pour un autre emplacement, ajoutez `DISCOVER_AGENT_INSTALL_URL=https://…/install.sh` (voir `install-agent.md`).

```bash
php artisan migrate --force
php artisan filament:optimize
php artisan optimize
```

## 4. Premier administrateur

Sans compte, la page `/admin/login` ne laisse entrer personne : créez-le maintenant, dans le
dossier `central/`, avec le même utilisateur que pour les commandes précédentes.

```bash
php artisan make:filament-user
```

La commande demande le nom, l'adresse e-mail et le mot de passe (8 caractères minimum). Évitez
les options `--password=…` : le mot de passe resterait dans l'historique du shell.

Puis :

1. ouvrez `https://<APP_URL>/admin` et connectez-vous avec cette adresse et ce mot de passe ;
2. Filament impose alors la configuration d'une **application d'authentification** (TOTP :
   Google Authenticator, 1Password, Bitwarden…) : scannez le QR code et saisissez le code à
   6 chiffres ;
3. **conservez les codes de secours** affichés ensuite : ils sont le seul moyen d'entrer si le
   téléphone est perdu.

Les administrateurs suivants se créent de la même façon (`php artisan make:filament-user`) ;
chacun configure son application d'authentification à sa première connexion.

Si un administrateur a perdu à la fois son application et ses codes de secours, retirez-lui son
second facteur ; il devra le reconfigurer à la connexion suivante :

```bash
php artisan tinker --execute "App\Models\User::where('email', 'admin@example.fr')->sole()
  ->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();"
```

Enfin, donnez au serveur web les droits d'écriture :

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

> **Hébergement Virtualmin** : le site tourne sous l'utilisateur du domaine (suexec), pas
> `www-data`. Lancez toutes les commandes `git`, `composer` et `php artisan` (y compris
> `make:filament-user`) en tant que cet utilisateur, jamais en root, par exemple
> `sudo -u <utilisateur> php artisan make:filament-user`, et remplacez `www-data` par lui dans le
> `chown`. Sinon des fichiers de `storage/` ou `bootstrap/cache/` appartiennent à root et
> l'application répond en erreur 500.

## 5. Apache (HTTPS obligatoire)

Les agents refusent toute URL qui n'est pas en `https://` et vérifient toujours le certificat.
Utilisez Let's Encrypt (central joignable publiquement) ou une autorité de certification interne
(les agents recevront alors `--ca-file`).

```apache
<VirtualHost *:443>
    ServerName discover.example.lan
    DocumentRoot /var/www/discover/central/public

    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile    /etc/ssl/discover/fullchain.pem
    SSLCertificateKeyFile /etc/ssl/discover/privkey.pem

    <Directory /var/www/discover/central/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # L'API refuse aussi les inventaires de plus de 5 Mo.
    <Location /api/v1/inventory>
        LimitRequestBody 6291456
    </Location>

    # Optionnel : n'accepter l'API que depuis le réseau des serveurs.
    # <Location /api/>
    #     Require ip 10.0.0.0/24
    # </Location>
</VirtualHost>

<VirtualHost *:80>
    ServerName discover.example.lan
    Redirect permanent / https://discover.example.lan/
</VirtualHost>
```

```bash
sudo a2enmod ssl proxy_fcgi setenvif rewrite
sudo a2ensite discover && sudo systemctl reload apache2
```

Si le central est derrière un reverse proxy, configurez les proxys de confiance de Laravel, sinon
l'« IP source » enregistrée pour chaque envoi sera celle du proxy.

## 6. Tâches planifiées

La purge des snapshots (30 jours, le dernier de chaque serveur est toujours gardé) tourne chaque
nuit à 3 h via le planificateur Laravel :

```bash
echo '* * * * * www-data cd /var/www/discover/central && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/discover
```

## 7. Épinglage de clé (optionnel)

Pour que les agents n'acceptent que la clé publique de ce central, calculez son empreinte :

```bash
openssl x509 -in /etc/ssl/discover/fullchain.pem -pubkey -noout \
  | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```

et passez-la aux agents avec `--pin-sha256`. Attention : un renouvellement de certificat avec une
**nouvelle clé** bloquera les agents épinglés jusqu'à leur réenrôlement (certbot réutilise la clé
avec `--reuse-key`).

## Mise à jour

```bash
sudo -u <propriétaire du dépôt> /var/www/discover/central/deploy.sh
```

Le script fait `git pull`, lance `composer install` seulement si `composer.lock` a changé, applique
les migrations (« Nothing to migrate » s'il n'y en a pas) et reconstruit les caches avec
`php artisan optimize` (qui inclut `filament:optimize`). Lancez-le en tant que propriétaire des
fichiers, **jamais en root** : des caches appartenant à root provoquent une erreur 500 (le script
refuse de tourner en root).

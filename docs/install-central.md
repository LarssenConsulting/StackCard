# Installer le central Discover

Le central est une application Laravel 13 + Filament 5. Il reçoit les inventaires des agents
(`POST /api/v1/inventory`) et les affiche dans une interface d'administration (`/admin`).

Cette procédure vise un serveur Ubuntu 24.04 avec Apache, PHP-FPM et MySQL/MariaDB. Le central
peut être hébergé sur l'un des serveurs inventoriés (qui aura alors aussi son agent).

## 1. Prérequis

```bash
sudo apt install apache2 mariadb-server git unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl \
  php8.3-zip php8.3-intl php8.3-bcmath
# Composer : https://getcomposer.org/download/
```

## 2. Base de données

```sql
CREATE DATABASE discover CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'discover_app'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT ALL PRIVILEGES ON discover.* TO 'discover_app'@'localhost';
```

## 3. Code et configuration

```bash
sudo git clone <dépôt> /var/www/discover
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

`APP_URL` sert aussi à construire la commande `discover-agent enroll` affichée dans l'interface.

```bash
php artisan migrate --force
php artisan filament:optimize
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 4. Apache (HTTPS obligatoire)

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
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
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

## 5. Tâches planifiées

La purge des snapshots (30 jours, le dernier de chaque serveur est toujours gardé) tourne chaque
nuit à 3 h via le planificateur Laravel :

```bash
echo '* * * * * www-data cd /var/www/discover/central && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/discover
```

## 6. Premier administrateur

```bash
php artisan make:filament-user
```

À la première connexion, Filament impose la configuration d'une application d'authentification
(TOTP : Google Authenticator, 1Password, Bitwarden…) et fournit des codes de secours.

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
cd /var/www/discover && sudo git pull
cd central && composer install --no-dev --optimize-autoloader
php artisan migrate --force && php artisan filament:optimize && php artisan optimize
```

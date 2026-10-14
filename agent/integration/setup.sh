#!/bin/bash
# Build a small but realistic web server inside an Ubuntu 24.04 image:
#   shop.example.test   :80 redirect + :443 PHP-FPM (pool "shop"), Laravel-style .env
#   api.example.test    :80 reverse proxy to a Node app on 127.0.0.1:3000 (user app-api)
#   static.example.test :80 plain DocumentRoot
#   MariaDB: shop_prod, api_prod, orphan_db + accounts shop/api
set -euo pipefail

useradd -m shop
useradd -r -m -d /srv/api app-api

a2enmod -q proxy proxy_fcgi proxy_http ssl setenvif
a2dissite -q 000-default

cat > /etc/php/8.3/fpm/pool.d/shop.conf <<'EOF'
[shop]
user = shop
group = shop
listen = /run/php/php8.3-fpm-shop.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 2
EOF

mkdir -p /etc/ssl/discover-test
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=shop.example.test \
    -keyout /etc/ssl/discover-test/shop.key -out /etc/ssl/discover-test/shop.pem 2>/dev/null

mkdir -p /var/www/shop/public /var/www/static
echo '<?php echo "shop";' > /var/www/shop/public/index.php
cat > /var/www/shop/.env <<'EOF'
APP_NAME=Shop
APP_KEY=base64:IntegrationTestKeyNotSecretAtAll=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shop_prod
DB_USERNAME=shop
DB_PASSWORD="S3cret-shop/#?"
EOF
chown -R shop:www-data /var/www/shop
echo static > /var/www/static/index.html

cat > /etc/apache2/sites-available/shop.conf <<'EOF'
<VirtualHost *:80>
    ServerName shop.example.test
    ServerAlias www.shop.example.test
    Redirect permanent / https://shop.example.test/
</VirtualHost>

<VirtualHost *:443>
    ServerName shop.example.test
    ServerAlias www.shop.example.test
    DocumentRoot /var/www/shop/public
    SSLEngine on
    SSLCertificateFile /etc/ssl/discover-test/shop.pem
    SSLCertificateKeyFile /etc/ssl/discover-test/shop.key
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm-shop.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
EOF

cat > /etc/apache2/sites-available/api.conf <<'EOF'
<VirtualHost *:80>
    ServerName api.example.test
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:3000/
    ProxyPassReverse / http://127.0.0.1:3000/
</VirtualHost>
EOF

cat > /etc/apache2/sites-available/static.conf <<'EOF'
<VirtualHost *:80>
    ServerName static.example.test
    DocumentRoot /var/www/static
</VirtualHost>
EOF
a2ensite -q shop api static

cat > /srv/api/server.js <<'EOF'
require("http").createServer((req, res) => res.end("api")).listen(3000, "127.0.0.1");
EOF
cat > /srv/api/.env <<'EOF'
NODE_ENV=production
DATABASE_URL=mysql://api:S3cret-api@localhost:3306/api_prod
JWT_SECRET=S3cret-jwt
EOF
chown -R app-api:app-api /srv/api

service mariadb start
mysql <<'EOF'
CREATE DATABASE shop_prod;
CREATE DATABASE api_prod;
CREATE DATABASE orphan_db;
CREATE USER 'shop'@'localhost' IDENTIFIED BY 'S3cret-shop/#?';
CREATE USER 'api'@'localhost' IDENTIFIED BY 'S3cret-api';
GRANT SELECT, INSERT, UPDATE, DELETE ON shop_prod.* TO 'shop'@'localhost';
GRANT ALL PRIVILEGES ON api_prod.* TO 'api'@'localhost';
CREATE TABLE shop_prod.products (id INT PRIMARY KEY, name VARCHAR(50));
EOF
service mariadb stop

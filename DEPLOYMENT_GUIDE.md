# Guide de Déploiement Production - Giga-PDF

## 🚀 Déploiement Rapide avec Docker

### 1. Prérequis Serveur
```bash
# Serveur Ubuntu 22.04 LTS avec:
- 4 CPU cores minimum
- 8GB RAM minimum
- 50GB SSD
- Docker & Docker Compose installés
```

### 2. Installation One-Command
```bash
# Cloner et déployer
git clone https://github.com/ronylicha/Giga-Pdf.git
cd Giga-Pdf
./deploy-production.sh
```

### 3. Script de Déploiement Automatique
Créer `deploy-production.sh`:
```bash
#!/bin/bash
set -e

echo "🚀 Déploiement de Giga-PDF en production..."

# Vérifier Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker n'est pas installé"
    exit 1
fi

# Configuration environnement
cp .env.production .env
read -p "Entrez votre domaine (ex: gigapdf.com): " DOMAIN
read -p "Entrez votre email pour SSL: " EMAIL

# Mise à jour .env
sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|g" .env
sed -i "s|APP_ENV=.*|APP_ENV=production|g" .env

# Build et lancement
docker-compose -f docker-compose.prod.yml up -d --build

# Attendre que les services démarrent
sleep 10

# Migrations
docker-compose exec app php artisan migrate --force
docker-compose exec app php artisan db:seed --class=ProductionSeeder

# Optimisations
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
docker-compose exec app php artisan view:cache
docker-compose exec app php artisan icons:cache

# SSL avec Certbot
docker-compose exec nginx certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos -n

echo "✅ Déploiement terminé!"
echo "🌐 Accédez à votre application: https://$DOMAIN"
```

## 🔧 Déploiement Manuel Détaillé

### Étape 1: Préparation du Serveur

#### Installation des Dépendances
```bash
# Mise à jour système
sudo apt update && sudo apt upgrade -y

# Installation PHP 8.4
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-common php8.4-mysql \
    php8.4-zip php8.4-gd php8.4-mbstring php8.4-curl php8.4-xml \
    php8.4-bcmath php8.4-redis php8.4-imagick

# Installation MariaDB
sudo apt install -y mariadb-server mariadb-client
sudo mysql_secure_installation

# Installation Redis
sudo apt install -y redis-server
sudo systemctl enable redis-server

# Installation Nginx
sudo apt install -y nginx

# Installation Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Installation Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Installation Supervisor
sudo apt install -y supervisor

# Installation des outils PDF
sudo apt install -y libreoffice tesseract-ocr poppler-utils
```

### Étape 2: Configuration Base de Données

```sql
sudo mysql -u root -p

CREATE DATABASE gigapdf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gigapdf'@'localhost' IDENTIFIED BY 'VotreMotDePasseSecure';
GRANT ALL PRIVILEGES ON gigapdf.* TO 'gigapdf'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Étape 3: Déploiement Application

```bash
# Créer le répertoire
sudo mkdir -p /var/www/html
cd /var/www/html

# Cloner le projet
sudo git clone https://github.com/ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf

# Permissions
sudo chown -R www-data:www-data /var/www/html/giga-pdf
sudo chmod -R 755 /var/www/html/giga-pdf
sudo chmod -R 775 storage bootstrap/cache

# Installation des dépendances
sudo -u www-data composer install --optimize-autoloader --no-dev
sudo -u www-data npm ci
sudo -u www-data npm run build

# Configuration
sudo cp .env.production .env
sudo -u www-data php artisan key:generate

# Éditer .env avec vos paramètres
sudo nano .env
```

### Étape 4: Configuration Nginx

Créer `/etc/nginx/sites-available/gigapdf`:
```nginx
server {
    listen 80;
    server_name votre-domaine.com;
    root /var/www/html/giga-pdf/public;

    index index.php;
    
    client_max_body_size 100M;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css text/javascript application/javascript application/json application/xml;
    gzip_vary on;
    gzip_min_length 1000;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 256 16k;
        fastcgi_busy_buffers_size 256k;
    }

    # WebSocket pour Laravel Reverb
    location /app {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }

    # Cache static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Activer le site:
```bash
sudo ln -s /etc/nginx/sites-available/gigapdf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Étape 5: Configuration Supervisor

Créer `/etc/supervisor/conf.d/gigapdf.conf`:
```ini
[program:gigapdf-horizon]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/horizon.log
stopwaitsecs=3600

[program:gigapdf-reverb]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/reverb.log

[program:gigapdf-scheduler]
process_name=%(program_name)s
command=/bin/bash -c "while [ true ]; do (php /var/www/html/giga-pdf/artisan schedule:run --verbose --no-interaction &); sleep 60; done"
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/scheduler.log
```

Démarrer les services:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### Étape 6: Finalisation

```bash
# Migrations et seeds
cd /var/www/html/giga-pdf
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=ProductionSeeder

# Optimisations
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan icons:cache
sudo -u www-data php artisan optimize

# Créer le premier super admin
sudo -u www-data php artisan make:super-admin admin@yourdomain.com "SecurePassword123!"

# Créer le premier tenant
sudo -u www-data php artisan tenant:create "Default Organization" --domain=default
```

### Étape 7: SSL avec Let's Encrypt

```bash
# Installation Certbot
sudo apt install -y certbot python3-certbot-nginx

# Obtenir le certificat
sudo certbot --nginx -d votre-domaine.com

# Renouvellement automatique
sudo certbot renew --dry-run
```

## 🔒 Sécurisation Post-Déploiement

### 1. Firewall Configuration
```bash
# UFW setup
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 2. Fail2ban Installation
```bash
sudo apt install -y fail2ban
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

### 3. Monitoring Setup
```bash
# Installation Netdata (monitoring système)
bash <(curl -Ss https://my-netdata.io/kickstart.sh)

# Configuration des alertes
# Éditer /etc/netdata/health_alarm_notify.conf
```

## 📊 Monitoring et Maintenance

### Health Checks
```bash
# Vérifier l'état de l'application
curl https://votre-domaine.com/health

# Vérifier les services
sudo supervisorctl status

# Vérifier les logs
tail -f /var/www/html/giga-pdf/storage/logs/laravel.log
```

### Backup Automatique
Créer `/usr/local/bin/backup-gigapdf.sh`:
```bash
#!/bin/bash
BACKUP_DIR="/var/backups/gigapdf"
DATE=$(date +%Y%m%d_%H%M%S)

# Créer le répertoire de backup
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u gigapdf -p gigapdf | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup files
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/html/giga-pdf/storage/app

# Garder seulement les 30 derniers backups
find $BACKUP_DIR -name "*.gz" -mtime +30 -delete

# Sync vers S3 (optionnel)
# aws s3 sync $BACKUP_DIR s3://your-backup-bucket/gigapdf/
```

Ajouter au crontab:
```bash
0 2 * * * /usr/local/bin/backup-gigapdf.sh
```

## 🚨 Troubleshooting

### Problèmes Courants

#### 1. Erreur 500
```bash
# Vérifier les logs
tail -n 100 /var/www/html/giga-pdf/storage/logs/laravel.log

# Vérifier les permissions
sudo chown -R www-data:www-data /var/www/html/giga-pdf
sudo chmod -R 775 storage bootstrap/cache
```

#### 2. Queue ne traite pas les jobs
```bash
# Redémarrer Horizon
sudo supervisorctl restart gigapdf-horizon

# Vérifier Redis
redis-cli ping
```

#### 3. WebSocket ne fonctionne pas
```bash
# Vérifier Reverb
sudo supervisorctl restart gigapdf-reverb

# Vérifier la configuration Nginx
nginx -t
```

## 📈 Scaling

### Horizontal Scaling avec Docker Swarm
```bash
# Initialiser Swarm
docker swarm init

# Déployer le stack
docker stack deploy -c docker-compose.prod.yml gigapdf

# Scaler les services
docker service scale gigapdf_app=5
```

### Load Balancing avec HAProxy
```
frontend web
    bind *:80
    bind *:443 ssl crt /etc/ssl/certs/gigapdf.pem
    redirect scheme https if !{ ssl_fc }
    default_backend servers

backend servers
    balance roundrobin
    server app1 10.0.1.1:80 check
    server app2 10.0.1.2:80 check
    server app3 10.0.1.3:80 check
```

## ✅ Checklist Finale

- [ ] Domaine configuré et DNS propagé
- [ ] SSL/TLS activé
- [ ] Firewall configuré
- [ ] Backup automatique configuré
- [ ] Monitoring en place
- [ ] Logs centralisés
- [ ] Super admin créé
- [ ] Premier tenant créé
- [ ] Tests de charge effectués
- [ ] Documentation mise à jour
- [ ] Équipe formée

## 🎉 Félicitations!

Votre instance Giga-PDF est maintenant opérationnelle en production!

**URLs importantes:**
- Application: https://votre-domaine.com
- Horizon Dashboard: https://votre-domaine.com/horizon
- Health Check: https://votre-domaine.com/health
- API: https://votre-domaine.com/api/v1

**Support:**
- Documentation: https://votre-domaine.com/docs
- Issues: https://github.com/ronylicha/Giga-Pdf/issues

---
Guide de déploiement v1.0 - Giga-PDF
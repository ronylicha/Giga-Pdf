# Déploiement Giga-PDF avec Ploi

Ce guide détaille le processus de déploiement de Giga-PDF sur Ploi.io

## Prérequis

### Serveur
- Ubuntu 22.04 LTS ou plus récent
- PHP 8.4+
- MySQL 8.0+ ou MariaDB 10.11+
- Redis 7.0+
- Node.js 18+ et NPM
- Minimum 2GB RAM (4GB recommandé)
- 20GB d'espace disque minimum

### Compte Ploi
- Accès à Ploi.io
- Serveur provisionné avec Ploi
- Domaine configuré

## Installation Initiale

### 1. Créer le Site dans Ploi

1. Dans Ploi, créez un nouveau site :
   - **Domain**: votre-domaine.com
   - **Project type**: Laravel
   - **PHP Version**: 8.4
   - **Database**: Créer une nouvelle base de données
   - **Web directory**: /public

### 2. Configuration de la Base de Données

Dans Ploi, créez une base de données MySQL :
```
Database name: gigapdf
Username: gigapdf
Password: [généré automatiquement]
```

### 3. Cloner le Repository

SSH dans votre serveur et clonez le repository :

```bash
cd /home/ploi/votre-domaine.com
rm -rf *  # Supprimer les fichiers par défaut
git clone https://github.com/votre-repo/giga-pdf.git .
```

### 4. Exécuter le Script d'Installation

```bash
# Donner les permissions d'exécution
chmod +x .ploi/install.sh

# Exécuter l'installation
sudo .ploi/install.sh /home/ploi/votre-domaine.com
```

### 5. Configuration de l'Environnement

Copiez et configurez le fichier .env :

```bash
cp .env.ploi.example .env
nano .env
```

Configurez les variables essentielles :
- `APP_URL`: https://votre-domaine.com
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: Depuis Ploi
- `REDIS_PASSWORD`: Si configuré
- `MAIL_*`: Vos paramètres SMTP

### 6. Configuration SSL

Dans Ploi :
1. Allez dans l'onglet SSL
2. Cliquez sur "Let's Encrypt"
3. Activez "Force HTTPS"

## Configuration des Workers/Daemons

### Option 1: Via l'Interface Ploi

Dans Ploi, ajoutez les daemons suivants :

#### Horizon (Queue Manager)
- **Command**: `php artisan horizon`
- **Directory**: Laissez vide (utilise le répertoire du site)
- **User**: ploi
- **Processes**: 1
- **Stop wait seconds**: 60

#### Reverb (WebSockets)
- **Command**: `php artisan reverb:start --host=0.0.0.0 --port=8080`
- **Directory**: Laissez vide
- **User**: ploi
- **Processes**: 1

#### Queue Worker - Conversions
- **Command**: `php artisan queue:work --queue=conversions --sleep=3 --tries=2 --timeout=300`
- **Directory**: Laissez vide
- **User**: ploi
- **Processes**: 3

#### Scheduler
- **Command**: `php artisan schedule:work`
- **Directory**: Laissez vide
- **User**: ploi
- **Processes**: 1

### Option 2: Import JSON

Importez le fichier `.ploi/daemons.json` directement dans Ploi.

## Configuration du Déploiement

### 1. Script de Déploiement

Dans Ploi, configurez le script de déploiement :

1. Allez dans l'onglet "Deployment"
2. Remplacez le script par défaut par :

```bash
cd /home/ploi/votre-domaine.com
git pull origin main
bash .ploi/deploy.sh
```

### 2. Webhooks GitHub (Optionnel)

Pour le déploiement automatique :
1. Dans Ploi, copiez l'URL du webhook
2. Dans GitHub, ajoutez le webhook dans Settings > Webhooks
3. Événement : Push sur la branche main

## Configuration Nginx (Personnalisée)

Si vous avez besoin de configurations Nginx spécifiques pour LibreOffice ou les uploads :

```nginx
# Dans Ploi > Site > Nginx Configuration

# Augmenter la taille max des uploads
client_max_body_size 100M;

# Timeout pour les conversions longues
proxy_read_timeout 300;
proxy_connect_timeout 300;
proxy_send_timeout 300;

# WebSocket support pour Reverb
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Installation des Dépendances Système

SSH dans votre serveur et installez les dépendances :

```bash
sudo apt-get update
sudo apt-get install -y \
    libreoffice \
    libreoffice-writer \
    libreoffice-calc \
    libreoffice-impress \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-fra \
    imagemagick \
    ghostscript \
    qpdf \
    python3-pip

# Python packages pour PDF avancé
sudo pip3 install --break-system-packages pypdf PyPDF2 PyMuPDF
```

## Monitoring et Health Checks

### Configuration dans Ploi

1. **Health Check URL**: https://votre-domaine.com/api/health
2. **Uptime Monitor**: https://votre-domaine.com/api/ping
3. **Expected Response Code**: 200

### Vérification Manuelle

```bash
# Vérifier l'état de l'application
curl https://votre-domaine.com/api/health

# Vérifier les workers
php artisan horizon:status

# Vérifier les logs
tail -f storage/logs/laravel.log
```

## Backup Configuration

### 1. Backup de Base de Données

Dans Ploi, configurez les backups :
1. Onglet "Backups"
2. Ajoutez un nouveau backup
3. Fréquence : Daily
4. Retention : 30 jours

### 2. Backup des Fichiers

Ajoutez un cron job dans Ploi :
```bash
0 2 * * * cd /home/ploi/votre-domaine.com && php artisan backup:run --only-files
```

## Optimisations Production

### 1. OPcache

Vérifiez que OPcache est activé :
```bash
php -i | grep opcache
```

### 2. Redis Configuration

Optimisez Redis pour les queues :
```bash
sudo nano /etc/redis/redis.conf
# maxmemory 256mb
# maxmemory-policy allkeys-lru
```

### 3. Monitoring des Performances

Installez New Relic ou Sentry (optionnel) :
```bash
# Dans .env
SENTRY_LARAVEL_DSN=votre-dsn-sentry
```

## Dépannage

### Problèmes Courants

#### 1. Permission Denied
```bash
sudo chown -R ploi:ploi /home/ploi/votre-domaine.com
chmod -R 775 storage bootstrap/cache
```

#### 2. LibreOffice ne fonctionne pas
```bash
# Vérifier l'installation
which libreoffice

# Tester manuellement
sudo -u ploi php artisan libreoffice:setup --check
```

#### 3. Queue ne traite pas les jobs
```bash
# Vérifier Horizon
php artisan horizon:status

# Redémarrer les workers
php artisan queue:restart
```

#### 4. WebSockets ne fonctionnent pas
```bash
# Vérifier que Reverb est lancé
ps aux | grep reverb

# Vérifier les ports
sudo netstat -tlnp | grep 8080
```

### Logs Utiles

- **Laravel**: `/home/ploi/votre-domaine.com/storage/logs/laravel.log`
- **Horizon**: `/home/ploi/votre-domaine.com/storage/logs/horizon.log`
- **Nginx**: `/var/log/nginx/votre-domaine.com-error.log`
- **PHP-FPM**: `/var/log/php8.4-fpm.log`

## Mise à Jour

Pour mettre à jour l'application :

### Automatique (via GitHub webhook)
Push sur la branche main déclenche automatiquement le déploiement.

### Manuel
```bash
cd /home/ploi/votre-domaine.com
git pull origin main
bash .ploi/deploy.sh
```

## Checklist de Déploiement

- [ ] Site créé dans Ploi
- [ ] Base de données configurée
- [ ] Repository cloné
- [ ] Script d'installation exécuté
- [ ] Fichier .env configuré
- [ ] SSL activé
- [ ] Daemons/Workers configurés
- [ ] Script de déploiement configuré
- [ ] Dépendances système installées
- [ ] Health check configuré
- [ ] Backups configurés
- [ ] Tests de conversion PDF réussis
- [ ] WebSockets fonctionnels
- [ ] Monitoring activé

## Support

Pour toute question ou problème :
1. Vérifiez les logs dans `/storage/logs/`
2. Testez le health check : `/api/health`
3. Consultez la documentation Laravel et Ploi
4. Ouvrez une issue sur GitHub

## Commandes Utiles

```bash
# État de l'application
php artisan about

# Tester les conversions
php artisan conversion:test 1 docx

# Nettoyer les fichiers temporaires
php artisan libreoffice:cleanup

# Vérifier l'installation LibreOffice
php artisan libreoffice:setup --check

# État des queues
php artisan queue:monitor

# État d'Horizon
php artisan horizon:status
```
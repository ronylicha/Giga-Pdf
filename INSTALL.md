# Installation de Giga-PDF

## 🚀 Installation Rapide

### Option 1 : Installation automatique (Recommandé)

```bash
# Cloner le repository
git clone git@github.com:ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf

# Lancer le script d'installation
./install.sh
```

Le script d'installation automatique va :
- Vérifier tous les prérequis système
- Installer les dépendances PHP et Node.js
- Configurer la base de données
- Créer le super admin avec nom et mot de passe personnalisés
- Configurer les workers et services
- Optimiser l'application pour la production

### Option 2 : Installation via Artisan

```bash
# Cloner le repository
git clone git@github.com:ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf

# Installer les dépendances
composer install
npm install

# Lancer la commande d'installation
php artisan gigapdf:install
```

Options disponibles :
- `--force` : Force la réinstallation même si déjà installé
- `--skip-deps` : Ignore la vérification des dépendances
- `--with-demo` : Installe avec des données de démonstration
- `--no-workers` : Ignore la configuration Supervisor

## 📋 Prérequis

### Obligatoires
- PHP >= 8.2 (8.4 recommandé)
- Composer
- Node.js >= 16 & NPM
- MariaDB 10.11+ ou MySQL 8.0+
- Extensions PHP :
  - BCMath, Ctype, JSON, Mbstring
  - OpenSSL, PDO, PDO MySQL
  - Tokenizer, XML, GD, Zip

### Recommandés
- Redis Server
- Imagick PHP Extension
- Tesseract OCR (pour l'OCR)
- pdftotext (pour l'extraction de texte)
- LibreOffice (pour les conversions de documents)
- Supervisor (pour les workers)

## 🔧 Installation Manuelle Détaillée

### 1. Préparation de l'environnement

```bash
# Cloner le repository
git clone git@github.com:ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf

# Copier le fichier d'environnement
cp .env.example .env

# Générer la clé d'application
php artisan key:generate
```

### 2. Configuration de la base de données

Éditer le fichier `.env` :

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gigapdf
DB_USERNAME=gigapdf_user
DB_PASSWORD=your_secure_password
```

Créer la base de données :

```sql
CREATE DATABASE gigapdf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gigapdf_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON gigapdf.* TO 'gigapdf_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Installation des dépendances

```bash
# Dépendances PHP
composer install --no-dev --optimize-autoloader

# Dépendances JavaScript
npm install

# Build des assets
npm run build
```

### 4. Migrations et configuration

```bash
# Exécuter les migrations
php artisan migrate

# Créer le lien symbolique pour le storage
php artisan storage:link

# Publier les configurations des packages
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
```

### 5. Création du Super Admin

```bash
# Utiliser la commande interactive
php artisan gigapdf:install
```

Ou créer manuellement via Tinker :

```bash
php artisan tinker
```

```php
use App\Models\Tenant;
use App\Models\User;

// Créer le tenant principal
$tenant = Tenant::create([
    'name' => 'Mon Organisation',
    'slug' => 'mon-organisation',
    'max_storage_gb' => 100,
    'max_users' => 100,
    'max_file_size_mb' => 100,
    'subscription_plan' => 'enterprise',
]);

// Créer le super admin
$user = User::create([
    'tenant_id' => $tenant->id,
    'name' => 'Super Admin',
    'email' => 'admin@example.com',
    'password' => Hash::make('your_password'),
    'email_verified_at' => now(),
    'role' => 'super_admin',
]);
```

### 6. Configuration des Workers (Production)

#### Avec Supervisor

Créer `/etc/supervisor/conf.d/gigapdf.conf` :

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

[program:gigapdf-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/giga-pdf/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/queue.log
stopwaitsecs=3600
```

Activer les workers :

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gigapdf:*
```

#### Avec Systemd

Créer les services dans `/etc/systemd/system/` :

```bash
# gigapdf-horizon.service
[Unit]
Description=Giga-PDF Horizon
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/giga-pdf
ExecStart=/usr/bin/php /var/www/html/giga-pdf/artisan horizon
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Activer les services :

```bash
sudo systemctl daemon-reload
sudo systemctl enable gigapdf-horizon
sudo systemctl start gigapdf-horizon
```

### 7. Configuration du Cron

Ajouter au crontab :

```bash
* * * * * cd /var/www/html/giga-pdf && php artisan schedule:run >> /dev/null 2>&1
```

### 8. Configuration Nginx (Production)

```nginx
server {
    listen 80;
    server_name gigapdf.yourdomain.com;
    root /var/www/html/giga-pdf/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    # WebSocket support for Laravel Reverb
    location /app {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 9. Optimisations pour la Production

```bash
# Cache de configuration
php artisan config:cache

# Cache des routes
php artisan route:cache

# Cache des vues
php artisan view:cache

# Cache des événements
php artisan event:cache

# Optimisation de l'autoloader
composer dump-autoload --optimize
```

## 🔒 Sécurité

### Permissions des fichiers

```bash
# Répertoires
find /var/www/html/giga-pdf -type d -exec chmod 755 {} \;

# Fichiers
find /var/www/html/giga-pdf -type f -exec chmod 644 {} \;

# Storage et cache
chmod -R 775 storage bootstrap/cache

# Propriétaire
sudo chown -R www-data:www-data /var/www/html/giga-pdf
```

### Variables d'environnement importantes

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Sécurité des sessions
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict

# 2FA
TWO_FACTOR_REQUIRED=true
```

## 🧪 Test de l'installation

### Développement

```bash
# Démarrer le serveur de développement
php artisan serve

# Dans des terminaux séparés :
php artisan queue:work
php artisan horizon
php artisan reverb:start
```

Accéder à : http://localhost:8000

### Production

Vérifier les services :

```bash
# Supervisor
sudo supervisorctl status

# Ou Systemd
sudo systemctl status gigapdf-*

# Logs
tail -f storage/logs/laravel.log
```

## 📝 Credentials par défaut (avec --with-demo)

Si vous avez utilisé l'option `--with-demo` :

- **Super Admin** : admin@gigapdf.local / (défini lors de l'installation)
- **Manager** : manager@demo.local / password
- **Editor** : editor@demo.local / password
- **Viewer** : viewer@demo.local / password

## 🆘 Dépannage

### Erreur de permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Erreur de base de données

```bash
# Vérifier la connexion
php artisan db:show

# Réexécuter les migrations
php artisan migrate:fresh --seed
```

### Assets non compilés

```bash
npm run build
php artisan storage:link
```

### Workers ne démarrent pas

```bash
# Vérifier Redis
redis-cli ping

# Relancer Horizon
php artisan horizon:terminate
php artisan horizon
```

## 📚 Documentation

Pour plus d'informations, consultez :
- [Documentation Laravel](https://laravel.com/docs)
- [Documentation Inertia.js](https://inertiajs.com)
- [Documentation Vue.js](https://vuejs.org)

## 📧 Support

Pour toute question ou problème :
- Email : support@gigapdf.com
- GitHub Issues : https://github.com/ronylicha/Giga-Pdf/issues
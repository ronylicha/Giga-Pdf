# Giga-PDF 🚀

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.x-green.svg)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)

Application web open source complète de gestion, édition et conversion de documents PDF construite avec Laravel 12, incluant un système multi-tenant avec authentification 2FA et interface d'administration avancée.

## ✨ Fonctionnalités

### 📄 Gestion des Documents
- **Import universel** : Support de 30+ formats (Word, Excel, PowerPoint, OpenDocument, Images, HTML, EPUB, etc.)
- **Export flexible** : Conversion vers tous les formats supportés
- **Organisation** : Système de dossiers, tags, et recherche full-text avec OCR

### 🔧 Opérations PDF Avancées
- **Manipulation** : Fusion, division, extraction, rotation et réorganisation de pages
- **Édition** : Modification de texte, annotations, ajout/suppression de contenu
- **Sécurité** : Chiffrement, signatures numériques, filigranes, permissions
- **Optimisation** : Compression intelligente, linearisation, suppression de métadonnées
- **Formulaires** : Création, remplissage automatique, extraction de données
- **OCR** : Reconnaissance de texte multilingue (12+ langues)
- **Comparaison** : Diff visuel entre versions
- **Redaction** : Suppression permanente de contenu sensible

### 🏢 Multi-tenancy
- Isolation complète des données par organisation
- Gestion des utilisateurs et rôles par tenant
- Limites configurables : stockage, utilisateurs, taille de fichiers
- Dashboard d'administration par tenant

### 🔐 Sécurité
- Authentification 2FA (TOTP/Email)
- Chiffrement des documents sensibles
- Audit log complet
- Rate limiting et protection CSRF/XSS
- Permissions granulaires par rôle

### 🤝 Partage et Collaboration
- Partage interne entre utilisateurs
- Liens publics avec expiration
- Liens protégés par mot de passe
- Intégration iframe pour sites web
- Permissions de partage configurables

### 🎨 Interface Moderne
- Design responsive (mobile/desktop)
- Mode sombre/clair
- Interface Vue.js 3 réactive
- Drag & drop pour upload
- Previews en temps réel
- Notifications WebSocket

## 🚀 Installation

### Prérequis
- Ubuntu 22.04 LTS ou équivalent
- PHP 8.4+ avec extensions : gd, imagick, zip, redis, mysqli/pdo_mysql
- MariaDB 10.11+ ou MySQL 8.0+
- Redis 7+
- Node.js 18+ et npm
- Composer 2.x
- LibreOffice 7+ (pour conversions)
- Tesseract OCR 5+ (pour OCR)

### Installation Automatique

```bash
# Cloner le repository
git clone https://github.com/ronylicha/Giga-Pdf.git
cd Giga-Pdf

# Lancer le script d'installation
./install.sh
```

### Installation Manuelle

#### 1. Cloner le projet
```bash
git clone https://github.com/ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf
```

#### 2. Installer les dépendances
```bash
# PHP
composer install --optimize-autoloader

# JavaScript
npm install
npm run build
```

#### 3. Configuration de l'environnement
```bash
# Copier le fichier de configuration
cp .env.example .env

# Générer la clé d'application
php artisan key:generate

# Éditer .env avec vos paramètres
nano .env
```

Configuration minimale dans `.env` :
```env
APP_NAME="Giga-PDF"
APP_URL=https://votre-domaine.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gigapdf
DB_USERNAME=votre_user
DB_PASSWORD=votre_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=votre.smtp.com
MAIL_PORT=587
MAIL_USERNAME=votre@email.com
MAIL_PASSWORD=votre_password
MAIL_ENCRYPTION=tls
```

#### 4. Installation de la base de données
```bash
# Créer les tables
php artisan migrate

# Créer le super admin et les données initiales
php artisan db:seed --class=ProductionSeeder

# Ou utiliser la commande d'installation complète
php artisan app:install
```

#### 5. Permissions des dossiers
```bash
# Définir les permissions appropriées
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data /var/www/html/giga-pdf

# Ou utiliser le script de permissions
./fix-permissions-prod.sh
```

#### 6. Configuration du serveur web (Nginx)

Créer `/etc/nginx/sites-available/giga-pdf` :
```nginx
server {
    listen 80;
    server_name votre-domaine.com;
    root /var/www/html/giga-pdf/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # WebSocket support pour Laravel Reverb
    location /app {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Activer le site :
```bash
ln -s /etc/nginx/sites-available/giga-pdf /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

#### 7. Configuration des services (Supervisor)

Créer `/etc/supervisor/conf.d/giga-pdf.conf` :
```ini
[program:giga-pdf-horizon]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/horizon.log
stopwaitsecs=3600

[program:giga-pdf-reverb]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/reverb.log
```

Démarrer les services :
```bash
supervisorctl reread
supervisorctl update
supervisorctl start giga-pdf:*
```

#### 8. Cron pour les tâches planifiées

Ajouter au crontab :
```bash
crontab -e
```

Ajouter cette ligne :
```cron
* * * * * cd /var/www/html/giga-pdf && php artisan schedule:run >> /dev/null 2>&1
```

#### 9. SSL avec Let's Encrypt (Optionnel mais recommandé)
```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d votre-domaine.com
```

## 🎯 Utilisation

### Créer le premier tenant
```bash
php artisan tenant:create "Mon Organisation" --domain=mon-org.exemple.com
```

### Créer un super administrateur
```bash
php artisan make:super-admin admin@exemple.com "MotDePasseSecure"
```

### Accès à l'application
1. Ouvrir https://votre-domaine.com
2. S'inscrire ou se connecter
3. Commencer à utiliser Giga-PDF !

## 📊 Limites par Défaut

Chaque organisation créée dispose par défaut de :
- **Stockage** : 1 GB
- **Utilisateurs** : 5
- **Taille max par fichier** : 25 MB
- **Accès à toutes les fonctionnalités**

Ces limites peuvent être ajustées par le super administrateur.

## 🛠️ Configuration Avancée

### Variables d'Environnement Importantes

```env
# Limites par défaut pour les nouveaux tenants
DEFAULT_TENANT_STORAGE_GB=1
DEFAULT_TENANT_MAX_USERS=5
DEFAULT_TENANT_MAX_FILE_SIZE_MB=25

# Configuration OCR
TESSERACT_PATH=/usr/bin/tesseract
OCR_LANGUAGES=fra,eng,deu,spa,ita

# LibreOffice pour conversions
LIBREOFFICE_PATH=/usr/bin/libreoffice
LIBREOFFICE_TIMEOUT=120

# Optimisations
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Sécurité
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
```

### Commandes Utiles

```bash
# Gestion des tenants
php artisan tenant:list                    # Lister tous les tenants
php artisan tenant:create "Nom" --domain=  # Créer un tenant
php artisan tenant:delete {id}             # Supprimer un tenant

# Maintenance
php artisan pdf:cleanup-temp              # Nettoyer les fichiers temporaires
php artisan pdf:optimize-storage          # Optimiser le stockage
php artisan backup:run                    # Backup manuel

# Monitoring
php artisan monitor:storage-usage         # Vérifier l'utilisation du stockage
php artisan monitor:tenant-limits         # Vérifier les limites des tenants
php artisan queue:monitor                 # Surveiller les queues

# Cache
php artisan config:cache                  # Cache de configuration
php artisan route:cache                   # Cache des routes
php artisan view:cache                    # Cache des vues
php artisan optimize                      # Optimisation globale
```

## 🐛 Dépannage

### Problèmes Courants

#### Erreur de permissions
```bash
./fix-permissions-prod.sh
# ou
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 755 storage bootstrap/cache
```

#### Queue ne traite pas les jobs
```bash
supervisorctl restart giga-pdf-horizon
php artisan horizon:terminate
```

#### Erreur de connexion WebSocket
```bash
# Vérifier que Reverb est en cours d'exécution
supervisorctl status giga-pdf-reverb
# Vérifier la configuration nginx pour /app
```

#### Problèmes de conversion PDF
```bash
# Vérifier que LibreOffice est installé
which libreoffice
# Test manuel
libreoffice --headless --convert-to pdf test.docx
```

## 🤝 Contribution

Les contributions sont les bienvenues ! 

1. Fork le projet
2. Créer une branche (`git checkout -b feature/AmazingFeature`)
3. Commit vos changements (`git commit -m 'Add some AmazingFeature'`)
4. Push vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrir une Pull Request

### Guidelines de Contribution
- Suivre les standards PSR-12 pour PHP
- Utiliser ESLint pour JavaScript
- Ajouter des tests pour les nouvelles fonctionnalités
- Mettre à jour la documentation si nécessaire

## 📝 Licence

Distribué sous la licence MIT. Voir `LICENSE` pour plus d'informations.

## 🙏 Remerciements

- [Laravel](https://laravel.com) - Framework PHP
- [Vue.js](https://vuejs.org) - Framework JavaScript
- [Inertia.js](https://inertiajs.com) - Adaptateur SPA
- [Tailwind CSS](https://tailwindcss.com) - Framework CSS
- [Spatie](https://spatie.be) - Packages Laravel de qualité
- [LibreOffice](https://www.libreoffice.org) - Conversions de documents
- [Tesseract OCR](https://github.com/tesseract-ocr/tesseract) - Reconnaissance de texte

## 📧 Contact

Rony Licha - [@ronylicha](https://github.com/ronylicha)

Lien du Projet : [https://github.com/ronylicha/Giga-Pdf](https://github.com/ronylicha/Giga-Pdf)

## 🔮 Roadmap

- [ ] API REST complète
- [ ] Application mobile
- [ ] Plugins navigateur
- [ ] Templates de documents
- [ ] Signature électronique avancée
- [ ] IA pour extraction de données
- [ ] Collaboration temps réel
- [ ] Intégrations cloud (Google Drive, Dropbox)
- [ ] Support Docker complet
- [ ] Tests E2E complets

---

**Giga-PDF** - Transformez vos documents en toute simplicité 🚀

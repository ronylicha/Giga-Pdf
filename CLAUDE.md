# Giga-PDF - Application de Gestion PDF Multi-tenant

## Vue d'ensemble
**Giga-PDF** est une application web complète de gestion, édition et conversion de documents PDF construite avec Laravel 12, incluant un système multi-tenant avec authentification 2FA, gestion avancée des PDF, et interface d'administration. L'application est déployée dans `/var/www/html/giga-pdf`.

## Stack Technique

### Backend
- **Framework**: Laravel 12
- **PHP**: 8.4+
- **Base de données**: MariaDB 10.11+ (compatible MySQL)
- **Cache**: Redis
- **Queue**: Laravel Horizon avec Redis
- **Websockets**: Laravel Reverb pour les notifications temps réel

### Frontend
- **Framework JS**: Vue.js 3 avec Inertia.js
- **CSS**: Tailwind CSS 3
- **Build**: Vite
- **Composants UI**: Headless UI + Heroicons

### Librairies PDF
- **Manipulation PDF**: Spatie/pdf-to-image, TCPDF, mPDF
- **Conversions**: LibreOffice (headless mode) via Docker
- **OCR**: Tesseract OCR
- **Édition**: PDF.js avec annotations

## Architecture

### Structure Multi-tenant
```
- Isolation par tenant_id
- Middleware TenantScope automatique
- Modèles avec trait BelongsToTenant
- Système de cache par tenant
```

### Structure des Dossiers
```
app/
├── Actions/
│   ├── PDF/
│   │   ├── ConvertDocument.php
│   │   ├── MergePDFs.php
│   │   ├── EditPDFContent.php
│   │   └── ...
│   └── Tenant/
├── Models/
│   ├── Tenant.php
│   ├── User.php
│   ├── Document.php
│   ├── Conversion.php
│   └── Share.php
├── Services/
│   ├── PDFService/
│   ├── ConversionService/
│   ├── OCRService/
│   └── TenantService/
├── Jobs/
│   ├── ProcessConversion.php
│   ├── GenerateThumbnail.php
│   └── SendNotification.php
└── Http/
    ├── Middleware/
    │   ├── EnsureTenantSelected.php
    │   ├── Require2FA.php
    │   └── CheckFileQuota.php
    └── Controllers/
        ├── DocumentController.php
        ├── ConversionController.php
        ├── EditorController.php
        └── Admin/
```

## Fonctionnalités Principales

### 1. Gestion des Documents

#### Import (Formats supportés)
- **Documents**: Word (.docx, .doc), Excel (.xlsx, .xls), PowerPoint (.pptx, .ppt), OpenDocument (.odt, .ods, .odp), RTF, TXT, Markdown
- **Images**: JPG, PNG, GIF, BMP, TIFF, SVG, WebP
- **Web**: HTML, MHTML, XML
- **E-books**: EPUB, MOBI
- **CAD**: DWG, DXF (via conversion)
- **Autres**: CSV, JSON (formaté), LaTeX

#### Export (Formats de sortie)
- Tous les formats d'import
- Formats additionnels: PDF/A, PDF/X, Images individuelles par page

#### Opérations PDF
- **Manipulation**: Fusion, division, extraction, rotation, réorganisation
- **Édition**: Modification de texte, ajout/suppression de contenu, annotations
- **Sécurité**: Chiffrement, signatures numériques, filigranes, permissions
- **Optimisation**: Compression, linearisation, suppression de métadonnées
- **Formulaires**: Création, remplissage, extraction de données
- **OCR**: Reconnaissance de texte avec support multilingue
- **Comparaison**: Diff entre versions
- **Redaction**: Suppression permanente de contenu sensible

### 2. Système Multi-tenant

#### Structure
```php
// Modèle Tenant
- id, name, domain, settings (JSON)
- max_storage_gb, max_users, max_file_size_mb
- features (JSON) // fonctionnalités activées
- subscription_plan, subscription_expires_at

// Relations
Tenant -> hasMany -> Users
Tenant -> hasMany -> Documents
Tenant -> hasMany -> Roles/Permissions
```

#### Rôles et Permissions
- **Super Admin**: Accès total système
- **Tenant Admin**: Gestion complète du tenant
- **Manager**: Gestion utilisateurs et documents
- **Editor**: Édition et conversion documents
- **Viewer**: Lecture seule

### 3. Authentification et Sécurité

#### 2FA Implementation
```php
// Support TOTP et Email
- QR Code generation pour Google Authenticator
- Codes de récupération
- Vérification à chaque connexion sensible
- Optionnel par utilisateur avec encouragement
```

#### Sécurité
- Rate limiting par IP et utilisateur
- Encryption at rest pour documents sensibles
- Audit log complet
- CSRF protection
- XSS protection via CSP

### 4. Interface Utilisateur

#### Dashboard Principal
- Vue grille/liste des documents
- Filtres avancés (type, date, taille, statut)
- Recherche full-text dans les PDF (avec OCR)
- Actions rapides (preview, download, share)
- Drag & drop pour upload

#### Éditeur PDF
- Interface WYSIWYG basée sur PDF.js
- Panneau d'outils latéral
- Historique des modifications (undo/redo)
- Collaboration temps réel (optionnel)
- Sauvegarde automatique

#### File d'Attente
- Vue temps réel des conversions (Websocket)
- Progression par étape
- Priorités de traitement
- Retry automatique en cas d'échec

### 5. Partage et Collaboration

#### Types de Partage
- **Interne**: Entre utilisateurs du même tenant
- **Lien public**: URL unique avec expiration optionnelle
- **Lien protégé**: Avec mot de passe
- **Embed**: Code iframe pour intégration

#### Permissions de Partage
- Lecture seule
- Téléchargement autorisé
- Édition (pour utilisateurs internes)
- Commentaires

### 6. Administration

#### Super Admin Dashboard
```
/admin
├── Tenants management
├── Global statistics
├── System health
├── Queue monitoring
├── Error logs
└── Billing/Subscriptions
```

#### Tenant Admin Dashboard
```
/tenant/admin
├── Users management
├── Roles & permissions
├── Storage usage
├── Activity logs
├── Settings
└── API tokens (future)
```

## Base de Données

### Tables Principales
```sql
-- tenants
id, name, slug, domain, settings JSON, max_storage_gb, max_users, 
max_file_size_mb, features JSON, subscription_plan, created_at, updated_at

-- users
id, tenant_id, name, email, password, two_factor_secret, 
two_factor_recovery_codes TEXT, role_id, created_at, updated_at

-- documents
id, tenant_id, user_id, original_name, stored_name, mime_type, 
size, hash, metadata JSON, is_public, created_at, updated_at

-- conversions
id, tenant_id, document_id, user_id, from_format, to_format, 
status, progress, error_message TEXT, started_at, completed_at

-- shares
id, document_id, shared_by, shared_with, type, permissions JSON, 
token, password, expires_at, created_at

-- activity_logs
id, tenant_id, user_id, action, subject_type, subject_id, 
properties JSON, ip_address, user_agent, created_at
```

### Optimisations MariaDB
```sql
-- Index pour performances multi-tenant
ALTER TABLE documents ADD INDEX idx_tenant_user (tenant_id, user_id);
ALTER TABLE documents ADD INDEX idx_tenant_created (tenant_id, created_at);
ALTER TABLE conversions ADD INDEX idx_tenant_status (tenant_id, status);

-- Full-text search avec MariaDB
ALTER TABLE documents ADD FULLTEXT ft_search (original_name, search_content);

-- Partitionnement pour grandes tables (optionnel)
ALTER TABLE activity_logs PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

## Configuration Environnement

### .env Principal
```env
# Application
APP_NAME="Giga-PDF"
APP_ENV=production
APP_DEBUG=false

# Database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=gigapdf
DB_USERNAME=gigapdf_user
DB_PASSWORD=strong_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis
HORIZON_PREFIX=horizon:gigapdf:

# Websockets (Reverb)
REVERB_APP_ID=gigapdf
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080

# Storage
FILESYSTEM_DISK=local
MAX_UPLOAD_SIZE=104857600 # 100MB default

# LibreOffice Docker
LIBREOFFICE_HOST=localhost
LIBREOFFICE_PORT=2004

# OCR
TESSERACT_PATH=/usr/bin/tesseract
OCR_LANGUAGES=eng,fra,deu,spa

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525

# 2FA
TWO_FACTOR_REQUIRED=false
TWO_FACTOR_RECOVERY_CODES=8
```

## Commandes Artisan Personnalisées

```bash
# Gestion des tenants
php artisan tenant:create {name} {--domain=}
php artisan tenant:migrate {tenant_id?}
php artisan tenant:seed {tenant_id?}

# Maintenance PDF
php artisan pdf:cleanup-temp
php artisan pdf:optimize-storage
php artisan pdf:reindex-search

# Monitoring
php artisan monitor:storage-usage
php artisan monitor:queue-health
php artisan monitor:tenant-limits
```

## Jobs et Queues

### Priorités des Queues
1. **high**: Opérations critiques (authentification, sécurité)
2. **default**: Conversions standards
3. **low**: Thumbnails, indexation
4. **notifications**: Emails et websockets

### Jobs Principaux
```php
// ProcessConversion.php
- Gestion des conversions avec retry logic
- Progress reporting via websocket
- Cleanup on failure

// GenerateThumbnail.php
- Création de previews
- Multiple résolutions
- Cache intelligent

// IndexDocument.php
- Extraction de texte pour recherche
- Métadonnées EXIF/PDF
- Tags automatiques
```

## Tests

### Structure des Tests
```
tests/
├── Unit/
│   ├── Services/
│   ├── Models/
│   └── Actions/
├── Feature/
│   ├── Auth/
│   ├── Documents/
│   ├── Conversions/
│   └── Admin/
└── Browser/ (Dusk)
    ├── LoginTest.php
    ├── PDFEditorTest.php
    └── AdminPanelTest.php
```

### Coverage Minimum
- Unit Tests: 80%
- Feature Tests: 70%
- Critical paths: 100%

## Déploiement

### Requirements Serveur
- Ubuntu 22.04 LTS ou équivalent
- PHP 8.4+ avec extensions: gd, imagick, zip, redis, mysqli/pdo_mysql
- MariaDB 10.11+
- Redis 7+
- LibreOffice 7+ (Docker)
- Tesseract OCR 5+
- Supervisor pour queues
- Nginx avec config WebSocket

### Processus de Déploiement
```bash
# 1. Clone et setup
cd /var/www/html
git clone git@github.com:ronylicha/Giga-Pdf.git giga-pdf
cd giga-pdf
composer install --optimize-autoloader --no-dev
npm install && npm run build

# 2. Configuration
cp .env.example .env
# Éditer .env avec les bonnes valeurs
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder

# 3. Optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan icons:cache

# 4. Permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data /var/www/html/giga-pdf

# 5. Supervisor & Cron
supervisorctl restart gigapdf:*
crontab -e # Add Laravel scheduler
```

### Configuration Nginx
```nginx
server {
    listen 80;
    server_name gigapdf.yourdomain.com;
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

    # WebSocket support for Laravel Reverb
    location /app {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Configuration Supervisor
```ini
[program:gigapdf-horizon]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/horizon.log

[program:gigapdf-reverb]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/reverb.log
```

## Monitoring et Logs

### Métriques Surveillées
- CPU/RAM par tenant
- Espace disque utilisé
- Queue length et processing time
- Taux de conversion réussite/échec
- Temps de réponse API
- Erreurs par type

### Dashboards
- Grafana pour métriques système
- Laravel Telescope en dev
- Custom admin dashboard pour business metrics

## Sécurité

### Checklist Sécurité
- [ ] HTTPS obligatoire
- [ ] Headers de sécurité (HSTS, CSP, X-Frame-Options)
- [ ] Rate limiting configuré
- [ ] Backup automatique quotidien
- [ ] Monitoring des tentatives de connexion
- [ ] Scan antivirus sur uploads
- [ ] Isolation des processus de conversion
- [ ] Audit log immutable

## Maintenance

### Tâches Planifiées
```php
// Kernel.php
$schedule->command('pdf:cleanup-temp')->daily();
$schedule->command('telescope:prune')->daily();
$schedule->command('backup:run')->daily();
$schedule->command('monitor:tenant-limits')->hourly();
$schedule->command('queue:restart')->hourly();
```

### Backup Strategy
- Database: Dump quotidien avec rétention 30 jours vers `/var/backups/giga-pdf/`
- Fichiers: Sync vers S3 avec versioning
- Code: Git tags pour chaque release

### Commandes de Maintenance
```bash
# Depuis le répertoire /var/www/html/giga-pdf

# Nettoyer les fichiers temporaires
php artisan pdf:cleanup-temp

# Optimiser le stockage
php artisan pdf:optimize-storage

# Vérifier la santé de l'application
php artisan health:check

# Backup manuel
php artisan backup:run --only-db
php artisan backup:run --only-files

# Monitoring des tenants
php artisan monitor:tenant-limits
php artisan monitor:storage-usage
```

## Performance

### Optimisations
- Cache aggressive avec tags
- Lazy loading des relations
- Chunking pour opérations bulk
- CDN pour assets statiques
- Compression Brotli/Gzip
- Database indexing optimisé
- Queue workers auto-scaling

### Limites Recommandées
- Max 100MB par fichier (configurable)
- Max 50 pages pour édition en ligne
- Max 10 conversions simultanées par utilisateur
- Session timeout: 2 heures

## Roadmap Future

### Phase 2
- API REST pour intégrations
- Application mobile
- Plugins navigateur
- Templates de documents
- Signature électronique avancée

### Phase 3
- IA pour extraction de données
- Collaboration temps réel
- Workflow automation
- Intégrations tierces (Google Drive, Dropbox)
- White-labeling complet

## Règles Techniques Importantes

### PDF.js et Frameworks Réactifs
**RÈGLE CRITIQUE**: Lors de l'utilisation de PDF.js avec Vue.js, Alpine.js ou tout autre framework réactif, NE JAMAIS stocker les instances PDF dans des propriétés réactives (ref, reactive, data). Les frameworks réactifs transforment les objets en Proxies pour la réactivité, ce qui casse les objets PDF.js.

Toujours stocker les instances PDF.js comme variables normales en dehors du système de réactivité :

```javascript
// ✅ BON - Variable normale
let pdfInstance = null;

// ❌ MAUVAIS - Propriété réactive
const pdfInstance = ref(null);
```

Cette règle s'applique à tous les objets complexes de bibliothèques tierces (PDF.js, Canvas, WebGL, etc.) qui ne sont pas conçus pour la réactivité.
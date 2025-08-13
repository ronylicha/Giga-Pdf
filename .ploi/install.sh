#!/bin/bash

# Ploi Initial Installation Script for Giga-PDF
# Run this script once after creating the site in Ploi

set -e

echo "
╔══════════════════════════════════════════════════════════╗
║         Giga-PDF Installation for Ploi                      ║
╚══════════════════════════════════════════════════════════╝
"

# Check if running as ploi user or root
if [ "$USER" != "ploi" ] && [ "$EUID" -ne 0 ]; then
    echo "❌ This script should be run as 'ploi' user or root"
    exit 1
fi

# Detect PHP version
PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
echo "📋 PHP Version detected: $PHP_VERSION"

# Install system dependencies
echo "📦 Installing system dependencies..."
if [ "$EUID" -eq 0 ]; then
    apt-get update
    apt-get install -y \
        libreoffice \
        libreoffice-writer \
        libreoffice-calc \
        libreoffice-impress \
        libreoffice-draw \
        poppler-utils \
        tesseract-ocr \
        tesseract-ocr-eng \
        tesseract-ocr-fra \
        imagemagick \
        ghostscript \
        qpdf \
        python3-pip
    
    # Install Python PDF libraries
    pip3 install --break-system-packages pypdf PyPDF2 PyMuPDF beautifulsoup4 lxml 2>/dev/null || \
    pip3 install pypdf PyPDF2 PyMuPDF beautifulsoup4 lxml
else
    echo "⚠️  Please run as root to install system dependencies:"
    echo "sudo apt-get update && sudo apt-get install -y libreoffice poppler-utils tesseract-ocr imagemagick ghostscript qpdf"
fi

# Navigate to site directory
SITE_PATH="${1:-/home/ploi/giga-pdf}"
cd $SITE_PATH

# Check if .env exists
if [ ! -f .env ]; then
    cp .env.example .env
    echo "⚠️  Created .env file - Please configure it with your settings"
    
    # Generate application key
    php artisan key:generate
fi

# Install composer dependencies
echo "📦 Installing Composer dependencies..."
composer install --optimize-autoloader

# Install NPM dependencies
echo "📦 Installing NPM dependencies..."
npm install

# Build assets
echo "🔨 Building assets..."
npm run build

# Setup all directories and permissions using dedicated script
echo "📁 Setting up directories and permissions..."
if [ -f "$SITE_PATH/.ploi/setup-directories.sh" ]; then
    bash $SITE_PATH/.ploi/setup-directories.sh $SITE_PATH
else
    # Fallback if script doesn't exist
    mkdir -p storage/app/libreoffice/{cache,config,temp}
    mkdir -p storage/app/conversions
    mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
    mkdir -p storage/app/private/{documents,conversions,thumbnails,temp}
    mkdir -p storage/framework/{sessions,views,cache}
    mkdir -p storage/logs
    mkdir -p storage/backups
    mkdir -p bootstrap/cache
    
    chmod -R 775 storage bootstrap/cache
    chmod -R 775 storage/app/libreoffice
    chmod -R 775 storage/app/conversions
    chown -R ploi:ploi storage bootstrap/cache
    chown -R ploi:ploi storage/app
fi

# Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link --force

# Setup LibreOffice
echo "🔧 Setting up LibreOffice..."
php artisan libreoffice:setup --force

# Cache configuration
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Install Giga-PDF
echo "🚀 Running Giga-PDF installation..."
php artisan gigapdf:install --force --skip-deps || true

# Create cron job for Laravel scheduler
echo "⏰ Setting up cron job..."
(crontab -l 2>/dev/null | grep -v "schedule:run.*$SITE_PATH" ; echo "* * * * * cd $SITE_PATH && php artisan schedule:run >> /dev/null 2>&1") | crontab -

echo "
✅ Installation completed!

Next steps:
1. Configure your .env file with database and Redis credentials
2. Set up your domain in Ploi
3. Configure SSL certificate
4. Add daemon configurations in Ploi:
   - Horizon: php artisan horizon
   - Reverb: php artisan reverb:start
   - Queue Worker: php artisan queue:work
5. Configure deployment script in Ploi to use .ploi/deploy.sh

Important Ploi configurations:
- PHP Version: 8.4+
- Node Version: 18+
- Deploy script: .ploi/deploy.sh
- Health check URL: /api/health

Daemon commands for Ploi:
- Horizon: php artisan horizon
- Queue Default: php artisan queue:work --sleep=3 --tries=3
- Queue Conversions: php artisan queue:work --queue=conversions --timeout=300
- Reverb WebSocket: php artisan reverb:start
- Scheduler: php artisan schedule:work
"
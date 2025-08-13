#!/bin/bash

# Giga-PDF Deployment Script for Ploi
# This script is designed to be used as a deployment hook in Ploi

set -e

echo "🚀 Starting Giga-PDF deployment via Ploi..."

# Detect if we're running in Ploi environment
if [ -n "$PLOI_SERVER_NAME" ] || [ -f /home/ploi/.ploi ]; then
    echo "✅ Ploi environment detected"
    IS_PLOI=true
else
    echo "⚠️  Not running in Ploi environment"
    IS_PLOI=false
fi

# Set variables based on environment
if [ "$IS_PLOI" = true ]; then
    # Ploi default paths
    PROJECT_DIR="${PLOI_SITE_DIRECTORY:-/home/ploi/${PLOI_SITE_NAME:-giga-pdf}/current}"
    WEB_USER="ploi"
    WEB_GROUP="ploi"
    PHP_VERSION="${PLOI_PHP_VERSION:-8.4}"
else
    # Fallback for non-Ploi environments
    PROJECT_DIR="/var/www/html/giga-pdf"
    WEB_USER="www-data"
    WEB_GROUP="www-data"
    PHP_VERSION="8.4"
fi

cd $PROJECT_DIR

echo "📦 Installing composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "📦 Installing NPM dependencies..."
npm ci --prefer-offline --no-audit

echo "🔨 Building assets..."
npm run build

echo "🗄️ Running database migrations..."
php artisan migrate --force

echo "🔧 Creating LibreOffice directories..."
# Create LibreOffice and conversion directories
mkdir -p storage/app/libreoffice/{cache,config,temp}
mkdir -p storage/app/conversions
mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
mkdir -p storage/app/private/{documents,conversions,thumbnails,temp}

# Set permissions for LibreOffice directories
chmod -R 775 storage/app/libreoffice
chmod -R 775 storage/app/conversions

# Set ownership if we have permission
if [ "$EUID" -eq 0 ] || [ "$IS_PLOI" = true ]; then
    chown -R $WEB_USER:$WEB_GROUP storage/app/libreoffice
    chown -R $WEB_USER:$WEB_GROUP storage/app/conversions
fi

echo "🔗 Creating storage link..."
php artisan storage:link --force

echo "🎨 Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "🔧 Setting up LibreOffice..."
php artisan libreoffice:setup || true

echo "📝 Setting file permissions..."
# Ensure proper permissions for Laravel
chmod -R 775 storage bootstrap/cache
chown -R $WEB_USER:$WEB_GROUP storage bootstrap/cache

# Ploi-specific: Restart PHP-FPM if in Ploi
if [ "$IS_PLOI" = true ]; then
    echo "🔄 Restarting PHP-FPM..."
    sudo service php$PHP_VERSION-fpm reload || true
fi

# Restart queue workers
echo "🔄 Restarting queue workers..."
php artisan queue:restart

# If Horizon is installed, terminate it so supervisor restarts it
if php artisan list | grep -q horizon; then
    echo "🔄 Restarting Horizon..."
    php artisan horizon:terminate || true
fi

# If Reverb is installed, restart it
if php artisan list | grep -q reverb; then
    echo "🔄 Restarting Reverb..."
    # Reverb will be managed by supervisor, just ensure it's configured
    echo "Note: Ensure Reverb is configured in your supervisor configuration"
fi

echo "✅ Deployment completed successfully!"

# Ploi-specific: Send notification
if [ "$IS_PLOI" = true ] && [ -n "$PLOI_WEBHOOK_URL" ]; then
    curl -X POST $PLOI_WEBHOOK_URL \
        -H "Content-Type: application/json" \
        -d "{\"text\":\"✅ Giga-PDF deployment completed on $PLOI_SERVER_NAME\"}" \
        2>/dev/null || true
fi

echo "
╔══════════════════════════════════════════════════════════╗
║           Giga-PDF Deployment Complete                      ║
╚══════════════════════════════════════════════════════════╝

Next steps:
1. Ensure queue workers are running (check Ploi daemon configuration)
2. Ensure Horizon is running if using Redis queues
3. Ensure LibreOffice is installed on the server
4. Check application at: https://$PLOI_SITE_NAME

"
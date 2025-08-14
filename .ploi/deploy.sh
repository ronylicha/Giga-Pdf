#!/bin/bash

# Ploi Deployment Script for Giga-PDF
# This file should be placed in .ploi/deploy.sh for automatic detection by Ploi

set -e

echo "🚀 Deploying Giga-PDF on Ploi..."

# Navigate to the release directory
cd {RELEASE_PATH}

# Copy environment file if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
    echo "⚠️  Please configure your .env file with proper values"
fi

# Install composer dependencies
echo "📦 Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Install NPM dependencies and build assets
echo "📦 Installing NPM dependencies..."
npm ci --prefer-offline --no-audit

echo "🔨 Building assets..."
npm run build

# Run database migrations
echo "🗄️ Running migrations..."
php artisan migrate --force

# Setup directories and fix permissions using dedicated scripts
echo "📁 Setting up directories and permissions..."
if [ -f ".ploi/setup-directories.sh" ]; then
    bash .ploi/setup-directories.sh {RELEASE_PATH}
elif [ -f ".ploi/fix-permissions.sh" ]; then
    bash .ploi/fix-permissions.sh {RELEASE_PATH}
else
    # Fallback if scripts don't exist
    mkdir -p storage/app/libreoffice/{cache,config,temp}
    mkdir -p storage/app/conversions
    mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
    mkdir -p storage/app/private/{documents,conversions,thumbnails,temp}
    mkdir -p storage/framework/{sessions,views,cache}
    mkdir -p storage/logs
    mkdir -p bootstrap/cache
    
    chmod -R 775 storage bootstrap/cache
    chmod -R 775 storage/app/libreoffice
    chmod -R 775 storage/app/conversions
    chmod -R 775 storage/app/private
    chmod -R 775 storage/app/temp
    chmod -R 775 storage/app/documents
    
    # Set proper ownership - www-data for web server writable directories
    chown -R www-data:www-data storage/app/libreoffice
    chown -R www-data:www-data storage/app/conversions
    chown -R www-data:www-data storage/app/private
    chown -R www-data:www-data storage/app/public
    chown -R www-data:www-data storage/app/temp
    chown -R www-data:www-data storage/app/documents
    chown -R www-data:www-data storage/framework
    chown -R www-data:www-data storage/logs
    chown -R www-data:www-data bootstrap/cache
fi

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link --force

# Cache configuration
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Setup LibreOffice
echo "🔧 Setting up LibreOffice..."
php artisan libreoffice:setup --force || true

# Clean up old files
echo "🧹 Cleaning up..."
php artisan libreoffice:cleanup --dry-run || true

# Restart queue workers
echo "🔄 Restarting queue workers..."
php artisan queue:restart

# Restart Horizon if it exists
if php artisan list | grep -q horizon; then
    php artisan horizon:terminate || true
fi

echo "✅ Deployment completed successfully!"
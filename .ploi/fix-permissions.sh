#!/bin/bash

# Fix permissions script for Giga-PDF
# This script ensures all directories have proper ownership and permissions

set -e

echo "🔧 Fixing permissions for Giga-PDF..."

# Get the site path from argument or use default
SITE_PATH="${1:-/home/ploi/giga-pdf.com}"
cd $SITE_PATH

# Create all necessary directories if they don't exist
echo "📁 Creating directory structure..."
mkdir -p storage/app/libreoffice/{cache,config,temp}
mkdir -p storage/app/conversions
mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
mkdir -p storage/app/private/{documents,conversions,thumbnails,temp,backups,certificates}
mkdir -p storage/app/documents/{1,2,3,4,5}  # Pre-create some tenant directories
mkdir -p storage/app/temp
mkdir -p storage/framework/{sessions,views,cache/data}
mkdir -p storage/logs
mkdir -p storage/backups
mkdir -p bootstrap/cache
mkdir -p public/downloads
mkdir -p public/uploads

# Set correct permissions for all directories
echo "🔒 Setting directory permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chmod -R 775 public/downloads
chmod -R 775 public/uploads

# Set proper ownership - www-data for web server writable directories
echo "👤 Setting ownership to www-data..."
sudo chown -R www-data:www-data storage/app
sudo chown -R www-data:www-data storage/framework
sudo chown -R www-data:www-data storage/logs
sudo chown -R www-data:www-data bootstrap/cache
sudo chown -R www-data:www-data public/downloads
sudo chown -R www-data:www-data public/uploads

# Ensure the ploi user can still access and deploy
echo "👥 Adding ploi to www-data group..."
sudo usermod -a -G www-data ploi 2>/dev/null || true

# Set SGID bit on directories so new files inherit group
echo "🔗 Setting SGID for group inheritance..."
find storage -type d -exec sudo chmod g+s {} \;
find bootstrap/cache -type d -exec sudo chmod g+s {} \;

# Special permissions for sensitive directories
echo "🔐 Setting special permissions..."
sudo chmod 750 storage/app/private
sudo chmod 750 storage/app/private/certificates
sudo chmod 750 storage/backups

echo "✅ Permissions fixed successfully!"

# Display current permissions for verification
echo ""
echo "📋 Current permissions:"
ls -la storage/app/ | head -n 15
echo ""
echo "Private directory:"
sudo ls -la storage/app/private/ | head -n 10
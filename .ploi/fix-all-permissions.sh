#!/bin/bash

# Comprehensive permissions fix script for Giga-PDF
# This script ensures ALL directories and files have proper ownership and permissions

set -e

echo "🔧 Comprehensive permissions fix for Giga-PDF..."

# Get the site path from argument or use default
SITE_PATH="${1:-/home/ploi/giga-pdf.com}"
cd $SITE_PATH

# Get sudo password if provided
SUDO_PASS="${2:-}"

# Function to run sudo commands
run_sudo() {
    if [ -n "$SUDO_PASS" ]; then
        echo "$SUDO_PASS" | sudo -S $@
    else
        sudo $@
    fi
}

echo "📁 Creating ALL necessary directories..."
# Storage directories
mkdir -p storage/app/libreoffice/{cache,config,temp}
mkdir -p storage/app/conversions
mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
mkdir -p storage/app/private/{documents,conversions,thumbnails,temp,backups,certificates}

# Create tenant directories (1-100)
for i in {1..100}; do
    mkdir -p storage/app/documents/$i
    mkdir -p storage/app/private/documents/$i
    mkdir -p storage/app/public/documents/$i
done

mkdir -p storage/app/temp
mkdir -p storage/app/fonts
mkdir -p storage/app/backup-temp
mkdir -p storage/framework/{sessions,views,cache/data}
mkdir -p storage/logs
mkdir -p storage/backups
mkdir -p bootstrap/cache
mkdir -p public/downloads
mkdir -p public/uploads

echo "🔒 Setting correct permissions for ALL directories..."
# Set base permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chmod -R 775 public/downloads 2>/dev/null || true
chmod -R 775 public/uploads 2>/dev/null || true

echo "👤 Setting ownership to www-data for ALL necessary directories..."
# Critical: Set ownership for all storage subdirectories
run_sudo chown -R www-data:www-data storage/
run_sudo chown -R www-data:www-data bootstrap/cache
run_sudo chown -R www-data:www-data public/downloads 2>/dev/null || true
run_sudo chown -R www-data:www-data public/uploads 2>/dev/null || true

echo "📝 Fixing log files permissions..."
# Ensure log files are writable
if [ -f storage/logs/laravel.log ]; then
    run_sudo chmod 666 storage/logs/laravel.log
    run_sudo chown www-data:www-data storage/logs/laravel.log
fi

# Fix all log files
run_sudo chmod 666 storage/logs/*.log 2>/dev/null || true

echo "👥 Adding ploi user to www-data group..."
run_sudo usermod -a -G www-data ploi 2>/dev/null || true
run_sudo usermod -a -G ploi www-data 2>/dev/null || true

echo "🔗 Setting SGID for group inheritance on ALL directories..."
# This ensures new files inherit the group
find storage -type d -exec run_sudo chmod g+s {} \; 2>/dev/null || true
find bootstrap/cache -type d -exec run_sudo chmod g+s {} \; 2>/dev/null || true

echo "🔧 Setting ACL permissions for better access control..."
# Use ACL for fine-grained permissions (if available)
if command -v setfacl >/dev/null 2>&1; then
    echo "Setting ACL permissions..."
    run_sudo setfacl -R -m u:www-data:rwx storage/ 2>/dev/null || true
    run_sudo setfacl -R -m u:ploi:rwx storage/ 2>/dev/null || true
    run_sudo setfacl -R -d -m u:www-data:rwx storage/ 2>/dev/null || true
    run_sudo setfacl -R -d -m u:ploi:rwx storage/ 2>/dev/null || true
fi

echo "🔐 Setting special permissions for sensitive directories..."
run_sudo chmod 750 storage/app/private 2>/dev/null || true
run_sudo chmod 750 storage/app/private/certificates 2>/dev/null || true
run_sudo chmod 750 storage/backups 2>/dev/null || true

echo "🧹 Clearing Laravel caches to avoid permission issues..."
php artisan cache:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

echo "✅ All permissions fixed successfully!"

# Display current permissions for verification
echo ""
echo "📋 Storage permissions:"
ls -la storage/ | head -n 10
echo ""
echo "📋 Storage/app permissions:"
ls -la storage/app/ | head -n 15
echo ""
echo "📋 Storage/logs permissions:"
ls -la storage/logs/ | head -n 10
echo ""
echo "📋 Private directory permissions:"
run_sudo ls -la storage/app/private/ | head -n 10

# Test write permissions
echo ""
echo "🧪 Testing write permissions..."
echo "Test" > storage/logs/test_write.txt 2>/dev/null && echo "✅ ploi can write to logs" && rm storage/logs/test_write.txt || echo "❌ ploi cannot write to logs"
run_sudo -u www-data bash -c "echo 'Test' > storage/logs/test_write_www.txt 2>/dev/null" && echo "✅ www-data can write to logs" && run_sudo rm storage/logs/test_write_www.txt || echo "❌ www-data cannot write to logs"
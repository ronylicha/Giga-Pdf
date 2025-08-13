#!/bin/bash

# Quick Setup Script for Ploi Deployment
# This script helps configure Giga-PDF on a fresh Ploi server

set -e

echo "
╔══════════════════════════════════════════════════════════╗
║         Giga-PDF Quick Setup for Ploi                       ║
╚══════════════════════════════════════════════════════════╝
"

# Check if running as ploi or root
if [ "$USER" != "ploi" ] && [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as 'ploi' user or with sudo"
    exit 1
fi

# Prompt for configuration
read -p "Enter your site directory [/home/ploi/giga-pdf]: " SITE_DIR
SITE_DIR=${SITE_DIR:-/home/ploi/giga-pdf}

read -p "Enter your domain (e.g., giga-pdf.com): " DOMAIN

read -p "Enter database name [gigapdf]: " DB_NAME
DB_NAME=${DB_NAME:-gigapdf}

read -p "Enter database username [gigapdf]: " DB_USER
DB_USER=${DB_USER:-gigapdf}

read -sp "Enter database password: " DB_PASS
echo ""

read -p "Enter your email for admin notifications: " ADMIN_EMAIL

# Navigate to site directory
cd $SITE_DIR

# Create .env file
echo "Creating .env file..."
cp .env.ploi.example .env

# Update .env with provided values
sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
sed -i "s|MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=noreply@$DOMAIN|" .env
sed -i "s|BACKUP_NOTIFICATION_EMAIL=.*|BACKUP_NOTIFICATION_EMAIL=$ADMIN_EMAIL|" .env

# Generate application key
php artisan key:generate

# Install dependencies
echo "Installing dependencies..."
composer install --optimize-autoloader --no-dev
npm ci && npm run build

# Setup directories and permissions
echo "Setting up directories..."
bash .ploi/install.sh $SITE_DIR

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Create admin user
echo ""
echo "Creating admin user..."
read -p "Admin name: " ADMIN_NAME
read -p "Admin email: " ADMIN_USER_EMAIL
read -sp "Admin password: " ADMIN_PASSWORD
echo ""

php artisan tinker --execute="
\$tenant = App\Models\Tenant::create([
    'name' => '$ADMIN_NAME Organization',
    'domain' => '$DOMAIN',
    'settings' => ['timezone' => 'UTC'],
    'max_users' => 100,
    'max_storage_gb' => 100,
]);

\$user = App\Models\User::create([
    'tenant_id' => \$tenant->id,
    'name' => '$ADMIN_NAME',
    'email' => '$ADMIN_USER_EMAIL',
    'password' => Hash::make('$ADMIN_PASSWORD'),
    'email_verified_at' => now(),
    'role' => 'admin',
]);

echo 'Admin user created successfully!';
"

# Configure cron job
echo "Setting up cron job..."
(crontab -l 2>/dev/null | grep -v "schedule:run.*$SITE_DIR" ; echo "* * * * * cd $SITE_DIR && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# Final optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "
✅ Setup completed successfully!

Next steps in Ploi dashboard:

1. Configure SSL certificate
2. Add the following daemons:
   - Horizon: php artisan horizon
   - Queue: php artisan queue:work --queue=conversions --timeout=300
   - Reverb: php artisan reverb:start --host=0.0.0.0 --port=8080
   - Scheduler: php artisan schedule:work

3. Configure deployment script to use: .ploi/deploy.sh

4. Set up health monitoring:
   - Health check URL: https://$DOMAIN/api/health
   - Uptime URL: https://$DOMAIN/api/ping

5. Configure backups in Ploi

You can now access your application at:
https://$DOMAIN

Admin credentials:
Email: $ADMIN_USER_EMAIL
Password: [the password you entered]
"
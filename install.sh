#!/bin/bash

#############################################
#         GIGA-PDF INSTALLATION SCRIPT     #
#############################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Banner
echo -e "${BLUE}"
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                  GIGA-PDF INSTALLER                       ║"
echo "║              Multi-tenant PDF Management System           ║"
echo "║                    Version 1.0.0                          ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
   echo -e "${RED}Please do not run this script as root!${NC}"
   exit 1
fi

# Function to check command existence
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to print status
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# System requirements check
echo -e "${BLUE}Checking system requirements...${NC}"

# Check PHP version
if command_exists php; then
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    PHP_MAJOR=$(echo $PHP_VERSION | cut -d. -f1)
    PHP_MINOR=$(echo $PHP_VERSION | cut -d. -f2)
    
    if [ "$PHP_MAJOR" -ge 8 ] && [ "$PHP_MINOR" -ge 2 ]; then
        print_status "PHP $PHP_VERSION installed"
    else
        print_error "PHP 8.2+ required (found $PHP_VERSION)"
        exit 1
    fi
else
    print_error "PHP not found"
    exit 1
fi

# Check Composer
if command_exists composer; then
    print_status "Composer installed"
else
    print_error "Composer not found"
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    print_status "Composer installed"
fi

# Check Node.js and npm
if command_exists node; then
    NODE_VERSION=$(node -v)
    print_status "Node.js $NODE_VERSION installed"
else
    print_error "Node.js not found"
    exit 1
fi

if command_exists npm; then
    NPM_VERSION=$(npm -v)
    print_status "npm $NPM_VERSION installed"
else
    print_error "npm not found"
    exit 1
fi

# Check for required PHP extensions
echo -e "\n${BLUE}Checking PHP extensions...${NC}"

REQUIRED_EXTENSIONS=(
    "bcmath"
    "ctype"
    "json"
    "mbstring"
    "openssl"
    "pdo"
    "pdo_mysql"
    "tokenizer"
    "xml"
    "gd"
    "zip"
)

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "^$ext$"; then
        print_status "PHP extension: $ext"
    else
        print_error "Missing PHP extension: $ext"
        MISSING_EXT=1
    fi
done

# Check optional extensions
OPTIONAL_EXTENSIONS=(
    "imagick"
    "redis"
)

for ext in "${OPTIONAL_EXTENSIONS[@]}"; do
    if php -m | grep -q "^$ext$"; then
        print_status "PHP extension: $ext (optional)"
    else
        print_warning "Missing optional PHP extension: $ext"
    fi
done

if [ "$MISSING_EXT" = "1" ]; then
    echo -e "${RED}Please install missing PHP extensions and try again${NC}"
    exit 1
fi

# Check for optional binaries
echo -e "\n${BLUE}Checking optional binaries...${NC}"

if command_exists redis-server; then
    print_status "Redis server installed"
else
    print_warning "Redis server not found (optional but recommended)"
fi

if command_exists tesseract; then
    print_status "Tesseract OCR installed"
else
    print_warning "Tesseract OCR not found (required for OCR features)"
fi

if command_exists pdftotext; then
    print_status "pdftotext installed"
else
    print_warning "pdftotext not found (required for PDF text extraction)"
fi

if command_exists libreoffice; then
    print_status "LibreOffice installed"
else
    print_warning "LibreOffice not found (required for document conversion)"
fi

# Installation confirmation
echo -e "\n${BLUE}Ready to install Giga-PDF${NC}"
read -p "Do you want to continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Installation cancelled"
    exit 1
fi

# Install dependencies
echo -e "\n${BLUE}Installing PHP dependencies...${NC}"
composer install --no-dev --optimize-autoloader

echo -e "\n${BLUE}Installing Node.js dependencies...${NC}"
npm install

# Build assets
echo -e "\n${BLUE}Building frontend assets...${NC}"
npm run build

# Run Laravel installation command
echo -e "\n${BLUE}Running Giga-PDF installation...${NC}"
php artisan gigapdf:install

# Create directories
echo -e "\n${BLUE}Creating required directories...${NC}"
mkdir -p storage/app/public
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
echo -e "\n${BLUE}Setting permissions...${NC}"
chmod -R 775 storage bootstrap/cache
chmod -R 755 public

# Optionally set ownership (requires sudo)
read -p "Do you want to set ownership to www-data? (requires sudo) (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    sudo chown -R www-data:www-data storage bootstrap/cache
    print_status "Permissions set for www-data"
fi

# Create systemd services (optional)
read -p "Do you want to create systemd services for workers? (requires sudo) (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo -e "\n${BLUE}Creating systemd services...${NC}"
    
    # Horizon service
    sudo tee /etc/systemd/system/gigapdf-horizon.service > /dev/null <<EOF
[Unit]
Description=Giga-PDF Horizon
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$(pwd)
ExecStart=/usr/bin/php $(pwd)/artisan horizon
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
EOF

    # Queue worker service
    sudo tee /etc/systemd/system/gigapdf-queue.service > /dev/null <<EOF
[Unit]
Description=Giga-PDF Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$(pwd)
ExecStart=/usr/bin/php $(pwd)/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
EOF

    # Reverb websocket service
    sudo tee /etc/systemd/system/gigapdf-reverb.service > /dev/null <<EOF
[Unit]
Description=Giga-PDF Reverb WebSocket
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$(pwd)
ExecStart=/usr/bin/php $(pwd)/artisan reverb:start
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
EOF

    # Reload systemd and enable services
    sudo systemctl daemon-reload
    sudo systemctl enable gigapdf-horizon gigapdf-queue gigapdf-reverb
    
    print_status "Systemd services created"
    
    echo -e "\n${BLUE}To start the services, run:${NC}"
    echo "sudo systemctl start gigapdf-horizon"
    echo "sudo systemctl start gigapdf-queue"
    echo "sudo systemctl start gigapdf-reverb"
fi

# Create cron job for Laravel scheduler
read -p "Do you want to add Laravel scheduler to crontab? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    (crontab -l 2>/dev/null; echo "* * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1") | crontab -
    print_status "Cron job added for Laravel scheduler"
fi

# Installation complete
echo -e "\n${GREEN}"
echo "╔══════════════════════════════════════════════════════════╗"
echo "║           INSTALLATION COMPLETED SUCCESSFULLY             ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "${BLUE}Next steps:${NC}"
echo ""
echo "1. Start the development server:"
echo "   php artisan serve"
echo ""
echo "2. Or for production, configure your web server (Nginx/Apache)"
echo ""
echo "3. Start the queue workers:"
echo "   php artisan queue:work"
echo "   php artisan horizon"
echo "   php artisan reverb:start"
echo ""
echo "4. Access the application at:"
echo "   http://localhost:8000"
echo ""
echo -e "${GREEN}Installation complete! Enjoy using Giga-PDF!${NC}"
#!/bin/bash

#################################################
# Simplified Ploi Installation Script for Giga-PDF
# This script installs all required dependencies
# for Ubuntu 24.04 LTS on Ploi
#################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Log function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   error "This script must be run as root (use sudo)"
   exit 1
fi

log "Starting Giga-PDF dependency installation for Ploi..."

#################################################
# SYSTEM UPDATE
#################################################
log "Updating system packages..."
apt-get update
apt-get upgrade -y

#################################################
# ESSENTIAL TOOLS
#################################################
log "Installing essential tools..."
apt-get install -y \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    curl \
    wget \
    git \
    zip \
    unzip \
    vim \
    nano \
    htop \
    cron

#################################################
# PHP 8.4 INSTALLATION (if not present)
#################################################
log "Checking PHP version..."
PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' || echo "0")
if [ "$(echo $PHP_VERSION | cut -d. -f1)" != "8" ] || [ "$(echo $PHP_VERSION | cut -d. -f2)" -lt "4" ]; then
    log "Installing PHP 8.4..."
    add-apt-repository -y ppa:ondrej/php || true
    apt-get update
    
    # Install PHP 8.4 and extensions
    apt-get install -y \
        php8.4-cli \
        php8.4-fpm \
        php8.4-common \
        php8.4-mysql \
        php8.4-xml \
        php8.4-xmlrpc \
        php8.4-curl \
        php8.4-gd \
        php8.4-imagick \
        php8.4-mbstring \
        php8.4-opcache \
        php8.4-soap \
        php8.4-zip \
        php8.4-intl \
        php8.4-bcmath \
        php8.4-redis \
        php8.4-readline \
        php8.4-gmp \
        php8.4-sqlite3 \
        php8.4-bz2
        
    # Set PHP 8.4 as default
    update-alternatives --set php /usr/bin/php8.4 || true
else
    log "PHP 8.4+ already installed"
fi

#################################################
# COMPOSER INSTALLATION
#################################################
log "Installing/Updating Composer..."
if ! command -v composer &> /dev/null; then
    EXPECTED_CHECKSUM="$(wget -q -O - https://composer.github.io/installer.sig)"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    
    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        error "Invalid Composer installer checksum"
        rm composer-setup.php
        exit 1
    fi
    
    php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php
else
    composer self-update --quiet || true
fi

#################################################
# NODE.JS AND NPM INSTALLATION
#################################################
log "Installing Node.js 20.x..."
if ! command -v node &> /dev/null || [ "$(node -v 2>/dev/null | cut -d'v' -f2 | cut -d'.' -f1)" -lt "20" ]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

# Update npm to latest
npm install -g npm@latest

#################################################
# MARIADB CLIENT (Server should be managed by Ploi)
#################################################
log "Installing MariaDB client tools..."
apt-get install -y mariadb-client

#################################################
# REDIS CLIENT (Server should be managed by Ploi)
#################################################
log "Installing Redis client tools..."
apt-get install -y redis-tools

#################################################
# PDF PROCESSING TOOLS
#################################################
log "Installing PDF processing tools..."

# LibreOffice for document conversion
apt-get install -y \
    libreoffice \
    libreoffice-writer \
    libreoffice-calc \
    libreoffice-impress \
    fonts-liberation \
    fonts-dejavu-core \
    fonts-noto

# Tesseract OCR with language packs
apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-fra \
    tesseract-ocr-deu \
    tesseract-ocr-spa \
    tesseract-ocr-ita

# PDF manipulation tools - Core
apt-get install -y \
    poppler-utils \
    ghostscript \
    imagemagick \
    pdftk \
    qpdf \
    wkhtmltopdf

# PDF manipulation tools - Advanced (for high-fidelity conversion)
apt-get install -y \
    pdf2svg \
    graphicsmagick \
    inkscape \
    librsvg2-bin \
    libcairo2-dev \
    libpango1.0-dev \
    libgdk-pixbuf2.0-dev \
    libffi-dev \
    shared-mime-info \
    mupdf-tools \
    pdfgrep

# Configure ImageMagick to allow PDF processing
if [ -f /etc/ImageMagick-6/policy.xml ]; then
    log "Configuring ImageMagick for PDF processing..."
    cp /etc/ImageMagick-6/policy.xml /etc/ImageMagick-6/policy.xml.backup
    sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/' /etc/ImageMagick-6/policy.xml
    sed -i 's/<policy domain="resource" name="memory" value="256MiB"\/>/<policy domain="resource" name="memory" value="2GiB"\/>/' /etc/ImageMagick-6/policy.xml
    sed -i 's/<policy domain="resource" name="map" value="512MiB"\/>/<policy domain="resource" name="map" value="4GiB"\/>/' /etc/ImageMagick-6/policy.xml
    sed -i 's/<policy domain="resource" name="disk" value="1GiB"\/>/<policy domain="resource" name="disk" value="8GiB"\/>/' /etc/ImageMagick-6/policy.xml
fi

if [ -f /etc/ImageMagick-7/policy.xml ]; then
    log "Configuring ImageMagick 7 for PDF processing..."
    cp /etc/ImageMagick-7/policy.xml /etc/ImageMagick-7/policy.xml.backup
    sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/' /etc/ImageMagick-7/policy.xml
    sed -i 's/<policy domain="resource" name="memory" value="256MiB"\/>/<policy domain="resource" name="memory" value="2GiB"\/>/' /etc/ImageMagick-7/policy.xml
    sed -i 's/<policy domain="resource" name="map" value="512MiB"\/>/<policy domain="resource" name="map" value="4GiB"\/>/' /etc/ImageMagick-7/policy.xml
    sed -i 's/<policy domain="resource" name="disk" value="1GiB"\/>/<policy domain="resource" name="disk" value="8GiB"\/>/' /etc/ImageMagick-7/policy.xml
fi

# Configure GraphicsMagick for optimal PDF processing
log "Configuring GraphicsMagick..."
mkdir -p /etc/GraphicsMagick
cat >> /etc/environment << 'EOF'
# GraphicsMagick configuration for Giga-PDF
export MAGICK_TMPDIR=/tmp
export MAGICK_MEMORY_LIMIT=2GB
export MAGICK_MAP_LIMIT=4GB
export MAGICK_DISK_LIMIT=8GB
export MAGICK_AREA_LIMIT=100MP
export MAGICK_WIDTH_LIMIT=50KP
export MAGICK_HEIGHT_LIMIT=50KP
EOF

#################################################
# SUPERVISOR INSTALLATION
#################################################
log "Installing and configuring Supervisor..."
apt-get install -y supervisor

# Create Supervisor configuration for Laravel Horizon
cat > /etc/supervisor/conf.d/gigapdf-horizon.conf << 'EOF'
[program:gigapdf-horizon]
process_name=%(program_name)s
command=php /home/ploi/giga-pdf.com/artisan horizon
autostart=true
autorestart=true
user=ploi
redirect_stderr=true
stdout_logfile=/home/ploi/giga-pdf.com/storage/logs/horizon.log
stopwaitsecs=3600
EOF

# Create Supervisor configuration for Laravel Reverb
cat > /etc/supervisor/conf.d/gigapdf-reverb.conf << 'EOF'
[program:gigapdf-reverb]
process_name=%(program_name)s
command=php /home/ploi/giga-pdf.com/artisan reverb:start
autostart=true
autorestart=true
user=ploi
redirect_stderr=true
stdout_logfile=/home/ploi/giga-pdf.com/storage/logs/reverb.log
EOF

# Create Supervisor configuration for Queue Worker
cat > /etc/supervisor/conf.d/gigapdf-queue.conf << 'EOF'
[program:gigapdf-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /home/ploi/giga-pdf.com/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=ploi
numprocs=2
redirect_stderr=true
stdout_logfile=/home/ploi/giga-pdf.com/storage/logs/queue.log
stopwaitsecs=3600
EOF

supervisorctl reread
supervisorctl update

#################################################
# DOCKER INSTALLATION (Optional - for LibreOffice container)
#################################################
log "Checking Docker installation..."
if ! command -v docker &> /dev/null; then
    log "Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
    
    # Add ploi user to docker group
    usermod -aG docker ploi || true
fi

#################################################
# SYSTEM OPTIMIZATION
#################################################
log "Optimizing system settings..."

# Increase file limits
cat >> /etc/security/limits.conf << EOF
* soft nofile 65536
* hard nofile 65536
ploi soft nofile 65536
ploi hard nofile 65536
EOF

# PHP-FPM Configuration
PHP_FPM_CONF="/etc/php/8.4/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_CONF" ]; then
    log "Configuring PHP-FPM for better performance..."
    sed -i 's/^pm = .*/pm = dynamic/' "$PHP_FPM_CONF"
    sed -i 's/^pm.max_children = .*/pm.max_children = 50/' "$PHP_FPM_CONF"
    sed -i 's/^pm.start_servers = .*/pm.start_servers = 5/' "$PHP_FPM_CONF"
    sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 5/' "$PHP_FPM_CONF"
    sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 35/' "$PHP_FPM_CONF"
fi

#################################################
# CLEANUP
#################################################
log "Cleaning up..."
apt-get autoremove -y
apt-get autoclean -y

#################################################
# RESTART SERVICES
#################################################
log "Restarting services..."
systemctl restart php8.4-fpm || true
systemctl restart nginx || true
supervisorctl restart all || true

#################################################
# VERIFICATION
#################################################
log "Verifying installations..."

echo ""
echo "==================================="
echo "Installation Summary:"
echo "==================================="

# Check PHP
if command -v php &> /dev/null; then
    echo -e "${GREEN}✓${NC} PHP: $(php -v | head -1)"
else
    echo -e "${RED}✗${NC} PHP installation failed"
fi

# Check Node.js
if command -v node &> /dev/null; then
    echo -e "${GREEN}✓${NC} Node.js: $(node -v)"
else
    echo -e "${RED}✗${NC} Node.js installation failed"
fi

# Check npm
if command -v npm &> /dev/null; then
    echo -e "${GREEN}✓${NC} npm: $(npm -v)"
else
    echo -e "${RED}✗${NC} npm installation failed"
fi

# Check Composer
if command -v composer &> /dev/null; then
    echo -e "${GREEN}✓${NC} Composer: $(composer --version --no-ansi | grep -oP 'Composer version \K[^ ]+')"
else
    echo -e "${RED}✗${NC} Composer installation failed"
fi

# Check LibreOffice
if command -v libreoffice &> /dev/null; then
    echo -e "${GREEN}✓${NC} LibreOffice: Installed"
else
    echo -e "${RED}✗${NC} LibreOffice installation failed"
fi

# Check Tesseract
if command -v tesseract &> /dev/null; then
    echo -e "${GREEN}✓${NC} Tesseract: $(tesseract --version 2>&1 | head -1)"
else
    echo -e "${RED}✗${NC} Tesseract installation failed"
fi

# Check PDF tools
if command -v pdftocairo &> /dev/null; then
    echo -e "${GREEN}✓${NC} pdftocairo: Installed"
else
    echo -e "${YELLOW}!${NC} pdftocairo not installed (affects PDF quality)"
fi

if command -v pdf2svg &> /dev/null; then
    echo -e "${GREEN}✓${NC} pdf2svg: Installed"
else
    echo -e "${YELLOW}!${NC} pdf2svg not installed (vector graphics extraction)"
fi

if command -v gm &> /dev/null; then
    echo -e "${GREEN}✓${NC} GraphicsMagick: Installed"
else
    echo -e "${YELLOW}!${NC} GraphicsMagick not installed (image processing)"
fi

if command -v inkscape &> /dev/null; then
    echo -e "${GREEN}✓${NC} Inkscape: Installed"
else
    echo -e "${YELLOW}!${NC} Inkscape not installed (SVG processing)"
fi

if command -v rsvg-convert &> /dev/null; then
    echo -e "${GREEN}✓${NC} librsvg: Installed"
else
    echo -e "${YELLOW}!${NC} librsvg not installed (SVG conversion)"
fi

# Check Docker
if command -v docker &> /dev/null; then
    echo -e "${GREEN}✓${NC} Docker: $(docker --version)"
else
    echo -e "${YELLOW}!${NC} Docker not installed (optional)"
fi

# Check Supervisor
if systemctl is-active --quiet supervisor; then
    echo -e "${GREEN}✓${NC} Supervisor: Active"
else
    echo -e "${RED}✗${NC} Supervisor not running"
fi

echo "==================================="
echo ""

log "Installation complete!"
echo ""
echo "Next steps for Ploi deployment:"
echo "1. Upload your application files to /home/ploi/giga-pdf.com"
echo "2. Configure your .env file with database credentials from Ploi"
echo "3. Run: cd /home/ploi/giga-pdf.com && composer install --optimize-autoloader --no-dev"
echo "4. Run: cd /home/ploi/giga-pdf.com && npm install && npm run build"
echo "5. Run: cd /home/ploi/giga-pdf.com && php artisan key:generate"
echo "6. Run: cd /home/ploi/giga-pdf.com && php artisan migrate --force"
echo "7. Run: cd /home/ploi/giga-pdf.com && php artisan storage:link"
echo "8. Configure your domain and SSL in Ploi dashboard"
echo "9. Set up deployment script in Ploi for automatic deployments"
echo ""
echo "Deployment script suggestion for Ploi:"
echo "----------------------------------------"
cat << 'DEPLOY_SCRIPT'
cd /home/ploi/giga-pdf.com
git pull origin main
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan horizon:terminate
supervisorctl restart gigapdf-horizon
supervisorctl restart gigapdf-reverb
supervisorctl restart gigapdf-queue:*
DEPLOY_SCRIPT
echo "----------------------------------------"
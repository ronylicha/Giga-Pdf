#!/bin/bash

#################################################
# Ploi Installation Script for Giga-PDF
# This script installs all required dependencies
# with proper version management
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

log "Starting Giga-PDF dependency installation..."

#################################################
# SYSTEM UPDATE
#################################################
log "Updating system packages..."
apt-get update
apt-get upgrade -y

#################################################
# PHP 8.4 INSTALLATION
#################################################
log "Installing PHP 8.4 and extensions..."

# Remove older PHP versions if they exist
for version in 7.4 8.0 8.1 8.2 8.3; do
    if dpkg -l | grep -q "php${version}"; then
        warning "Removing PHP ${version}..."
        apt-get remove --purge -y php${version}* || true
    fi
done

# Add PHP repository if not exists
if ! grep -q "ondrej/php" /etc/apt/sources.list.d/*.list 2>/dev/null; then
    log "Adding PHP repository..."
    apt-get install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update
fi

# Install PHP 8.4 and required extensions
PHP_PACKAGES=(
    php8.4-cli
    php8.4-fpm
    php8.4-common
    php8.4-mysql
    php8.4-xml
    php8.4-xmlrpc
    php8.4-curl
    php8.4-gd
    php8.4-imagick
    php8.4-cli
    php8.4-dev
    php8.4-imap
    php8.4-mbstring
    php8.4-opcache
    php8.4-soap
    php8.4-zip
    php8.4-intl
    php8.4-bcmath
    php8.4-redis
    php8.4-readline
    php8.4-msgpack
    php8.4-igbinary
    php8.4-ldap
    php8.4-gmp
    php8.4-pgsql
    php8.4-sqlite3
    php8.4-bz2
)

for package in "${PHP_PACKAGES[@]}"; do
    log "Installing ${package}..."
    apt-get install -y $package
done

# Set PHP 8.4 as default
update-alternatives --set php /usr/bin/php8.4
update-alternatives --set phar /usr/bin/phar8.4
update-alternatives --set phar.phar /usr/bin/phar.phar8.4

# Configure PHP settings
log "Configuring PHP settings..."
PHP_INI="/etc/php/8.4/fpm/php.ini"
PHP_CLI_INI="/etc/php/8.4/cli/php.ini"

for ini_file in "$PHP_INI" "$PHP_CLI_INI"; do
    if [ -f "$ini_file" ]; then
        sed -i 's/upload_max_filesize = .*/upload_max_filesize = 100M/' "$ini_file"
        sed -i 's/post_max_size = .*/post_max_size = 100M/' "$ini_file"
        sed -i 's/memory_limit = .*/memory_limit = 512M/' "$ini_file"
        sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$ini_file"
        sed -i 's/max_input_time = .*/max_input_time = 300/' "$ini_file"
    fi
done

#################################################
# MARIADB INSTALLATION
#################################################
log "Installing MariaDB 10.11+..."

# Remove older MySQL/MariaDB versions
if dpkg -l | grep -q "mysql-server\|mariadb-server"; then
    warning "Removing existing MySQL/MariaDB installations..."
    systemctl stop mysql 2>/dev/null || true
    systemctl stop mariadb 2>/dev/null || true
    apt-get remove --purge -y mysql* mariadb* || true
    apt-get autoremove -y
    rm -rf /etc/mysql /var/lib/mysql
fi

# Add MariaDB repository
if ! grep -q "mariadb" /etc/apt/sources.list.d/*.list 2>/dev/null; then
    log "Adding MariaDB repository..."
    apt-get install -y apt-transport-https curl
    curl -o /etc/apt/trusted.gpg.d/mariadb_release_signing_key.asc 'https://mariadb.org/mariadb_release_signing_key.asc'
    echo "deb [arch=amd64,arm64,ppc64el] https://mirror.mariadb.org/repo/11.4/ubuntu $(lsb_release -cs) main" > /etc/apt/sources.list.d/mariadb.list
    apt-get update
fi

# Install MariaDB
apt-get install -y mariadb-server mariadb-client

# Start and enable MariaDB
systemctl start mariadb
systemctl enable mariadb

# Secure MariaDB installation (non-interactive)
mysql -e "UPDATE mysql.user SET Password=PASSWORD('$(openssl rand -base64 32)') WHERE User='root';"
mysql -e "DELETE FROM mysql.user WHERE User='';"
mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
mysql -e "DROP DATABASE IF EXISTS test;"
mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
mysql -e "FLUSH PRIVILEGES;"

#################################################
# REDIS INSTALLATION
#################################################
log "Installing Redis 7+..."

# Remove older Redis versions
if dpkg -l | grep -q "redis"; then
    REDIS_VERSION=$(redis-server --version 2>/dev/null | grep -oP 'v=\K[0-9]+' || echo "0")
    if [ "$REDIS_VERSION" -lt "7" ]; then
        warning "Removing older Redis version..."
        systemctl stop redis-server 2>/dev/null || true
        apt-get remove --purge -y redis* || true
    fi
fi

# Install Redis
apt-get install -y redis-server redis-tools

# Configure Redis
sed -i 's/^# maxmemory <bytes>/maxmemory 256mb/' /etc/redis/redis.conf
sed -i 's/^# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
sed -i 's/^supervised no/supervised systemd/' /etc/redis/redis.conf

# Start and enable Redis
systemctl restart redis-server
systemctl enable redis-server

#################################################
# NODE.JS AND NPM INSTALLATION
#################################################
log "Installing Node.js 20.x and npm..."

# Remove older Node versions
if command -v node &> /dev/null; then
    NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
    if [ "$NODE_VERSION" -lt "20" ]; then
        warning "Removing older Node.js version..."
        apt-get remove --purge -y nodejs npm || true
    fi
fi

# Install Node.js 20.x
if ! command -v node &> /dev/null || [ "$(node -v | cut -d'v' -f2 | cut -d'.' -f1)" -lt "20" ]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

# Update npm to latest
npm install -g npm@latest

#################################################
# COMPOSER INSTALLATION
#################################################
log "Installing Composer..."

# Download and install Composer
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
else
    composer self-update
fi

#################################################
# LIBREOFFICE INSTALLATION
#################################################
log "Installing LibreOffice..."

# Remove older LibreOffice versions
if dpkg -l | grep -q "libreoffice"; then
    LIBREOFFICE_VERSION=$(libreoffice --version 2>/dev/null | grep -oP 'LibreOffice \K[0-9]+' || echo "0")
    if [ "$LIBREOFFICE_VERSION" -lt "7" ]; then
        warning "Removing older LibreOffice version..."
        apt-get remove --purge -y libreoffice* || true
    fi
fi

# Install LibreOffice
apt-get install -y libreoffice libreoffice-writer libreoffice-calc libreoffice-impress

# Install fonts for better PDF rendering
apt-get install -y fonts-liberation fonts-dejavu-core fonts-noto

#################################################
# TESSERACT OCR INSTALLATION
#################################################
log "Installing Tesseract OCR..."

# Remove older Tesseract versions
if command -v tesseract &> /dev/null; then
    TESSERACT_VERSION=$(tesseract --version 2>/dev/null | head -1 | grep -oP 'tesseract \K[0-9]+' || echo "0")
    if [ "$TESSERACT_VERSION" -lt "5" ]; then
        warning "Removing older Tesseract version..."
        apt-get remove --purge -y tesseract* || true
    fi
fi

# Install Tesseract OCR with language packs
apt-get install -y tesseract-ocr tesseract-ocr-eng tesseract-ocr-fra tesseract-ocr-deu tesseract-ocr-spa

#################################################
# PDF TOOLS INSTALLATION
#################################################
log "Installing PDF tools..."

apt-get install -y \
    poppler-utils \
    ghostscript \
    imagemagick \
    pdftk \
    qpdf \
    wkhtmltopdf

# Configure ImageMagick to allow PDF processing
sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/' /etc/ImageMagick-6/policy.xml 2>/dev/null || true

#################################################
# SUPERVISOR INSTALLATION
#################################################
log "Installing Supervisor..."

apt-get install -y supervisor

# Create Supervisor configuration for Laravel Horizon
cat > /etc/supervisor/conf.d/gigapdf-horizon.conf << 'EOF'
[program:gigapdf-horizon]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/horizon.log
stopwaitsecs=3600
EOF

# Create Supervisor configuration for Laravel Reverb
cat > /etc/supervisor/conf.d/gigapdf-reverb.conf << 'EOF'
[program:gigapdf-reverb]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/reverb.log
EOF

supervisorctl reread
supervisorctl update

#################################################
# NGINX CONFIGURATION
#################################################
log "Configuring Nginx for WebSocket support..."

# Check if Nginx configuration exists
if [ -f "/etc/nginx/sites-available/gigapdf" ]; then
    # Add WebSocket support if not already present
    if ! grep -q "location /app" /etc/nginx/sites-available/gigapdf; then
        sed -i '/server {/a\
    \n    # WebSocket support for Laravel Reverb\
    location /app {\
        proxy_pass http://localhost:8080;\
        proxy_http_version 1.1;\
        proxy_set_header Upgrade $http_upgrade;\
        proxy_set_header Connection "upgrade";\
        proxy_set_header Host $host;\
        proxy_set_header X-Real-IP $remote_addr;\
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\
        proxy_set_header X-Forwarded-Proto $scheme;\
    }' /etc/nginx/sites-available/gigapdf
        
        nginx -t && systemctl reload nginx
    fi
fi

#################################################
# SYSTEM TOOLS
#################################################
log "Installing system tools..."

apt-get install -y \
    git \
    curl \
    wget \
    zip \
    unzip \
    htop \
    vim \
    nano \
    cron \
    certbot \
    python3-certbot-nginx

#################################################
# DOCKER INSTALLATION (for LibreOffice container)
#################################################
log "Installing Docker..."

if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
fi

# Add www-data user to docker group
usermod -aG docker www-data

# Pull LibreOffice Docker image
docker pull libreoffice/online:latest || warning "Could not pull LibreOffice Docker image"

#################################################
# FIREWALL CONFIGURATION
#################################################
log "Configuring firewall..."

apt-get install -y ufw

# Configure UFW
ufw --force enable
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw allow 8080/tcp  # Laravel Reverb WebSocket
ufw reload

#################################################
# SYSTEM OPTIMIZATION
#################################################
log "Optimizing system settings..."

# Increase file limits
cat >> /etc/security/limits.conf << EOF
* soft nofile 65536
* hard nofile 65536
www-data soft nofile 65536
www-data hard nofile 65536
EOF

# Optimize sysctl settings
cat >> /etc/sysctl.conf << EOF
# Network optimizations
net.core.somaxconn = 65536
net.ipv4.tcp_max_tw_buckets = 1440000
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_fin_timeout = 15
net.ipv4.tcp_window_scaling = 1
net.ipv4.tcp_max_syn_backlog = 8192

# File system
fs.file-max = 2097152
fs.inotify.max_user_watches = 524288
EOF

sysctl -p

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
systemctl restart php8.4-fpm
systemctl restart nginx
systemctl restart mariadb
systemctl restart redis-server
supervisorctl restart all

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

# Check MariaDB
if systemctl is-active --quiet mariadb; then
    echo -e "${GREEN}✓${NC} MariaDB: $(mysql --version | cut -d' ' -f6)"
else
    echo -e "${RED}✗${NC} MariaDB installation failed"
fi

# Check Redis
if systemctl is-active --quiet redis-server; then
    echo -e "${GREEN}✓${NC} Redis: $(redis-server --version | cut -d' ' -f3)"
else
    echo -e "${RED}✗${NC} Redis installation failed"
fi

# Check Node.js
if command -v node &> /dev/null; then
    echo -e "${GREEN}✓${NC} Node.js: $(node -v)"
else
    echo -e "${RED}✗${NC} Node.js installation failed"
fi

# Check Composer
if command -v composer &> /dev/null; then
    echo -e "${GREEN}✓${NC} Composer: $(composer --version | cut -d' ' -f3)"
else
    echo -e "${RED}✗${NC} Composer installation failed"
fi

# Check LibreOffice
if command -v libreoffice &> /dev/null; then
    echo -e "${GREEN}✓${NC} LibreOffice: $(libreoffice --version | head -1)"
else
    echo -e "${RED}✗${NC} LibreOffice installation failed"
fi

# Check Tesseract
if command -v tesseract &> /dev/null; then
    echo -e "${GREEN}✓${NC} Tesseract: $(tesseract --version 2>&1 | head -1)"
else
    echo -e "${RED}✗${NC} Tesseract installation failed"
fi

# Check Docker
if command -v docker &> /dev/null; then
    echo -e "${GREEN}✓${NC} Docker: $(docker --version)"
else
    echo -e "${RED}✗${NC} Docker installation failed"
fi

echo "==================================="
echo ""

log "Installation complete!"
log "Please run the application setup script next."

# Create post-installation script
cat > /var/www/html/giga-pdf/ploi-post-install.sh << 'POSTINSTALL'
#!/bin/bash

# Post-installation setup for Giga-PDF

cd /var/www/html/giga-pdf

# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Install Node dependencies and build assets
npm install
npm run build

# Setup Laravel
cp .env.example .env
php artisan key:generate
php artisan storage:link

# Run migrations
php artisan migrate --force

# Cache configurations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
chown -R www-data:www-data /var/www/html/giga-pdf
chmod -R 755 storage bootstrap/cache

# Setup cron job for Laravel scheduler
(crontab -l 2>/dev/null; echo "* * * * * cd /var/www/html/giga-pdf && php artisan schedule:run >> /dev/null 2>&1") | crontab -

echo "Post-installation setup complete!"
POSTINSTALL

chmod +x /var/www/html/giga-pdf/ploi-post-install.sh

echo ""
echo "Next steps:"
echo "1. Configure your .env file with database credentials"
echo "2. Run: /var/www/html/giga-pdf/ploi-post-install.sh"
echo "3. Configure your domain in Nginx"
echo "4. Setup SSL with: certbot --nginx -d yourdomain.com"
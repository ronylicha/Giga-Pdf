#!/bin/bash

# Giga-PDF Installation Script
# Multi-tenant PDF Management System

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}"
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                  GIGA-PDF INSTALLATION                    ║"
echo "║           Multi-tenant PDF Management System              ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to install system dependencies
install_system_deps() {
    echo -e "${YELLOW}Installing system dependencies...${NC}"
    
    # Detect OS
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        if command_exists apt-get; then
            # Ubuntu/Debian
            sudo apt-get update
            sudo apt-get install -y \
                php8.2-cli php8.2-fpm php8.2-mysql php8.2-mbstring \
                php8.2-xml php8.2-curl php8.2-gd php8.2-imagick \
                php8.2-zip php8.2-bcmath php8.2-redis \
                mariadb-server redis-server \
                poppler-utils ghostscript \
                wkhtmltopdf wkhtmltoimage \
                tesseract-ocr tesseract-ocr-fra \
                imagemagick libmagickwand-dev \
                python3 python3-pip python3-dev \
                default-jre \
                git curl wget unzip
        elif command_exists yum; then
            # RHEL/CentOS
            sudo yum install -y epel-release
            sudo yum install -y \
                php82 php82-php-fpm php82-php-mysqlnd php82-php-mbstring \
                php82-php-xml php82-php-curl php82-php-gd php82-php-imagick \
                php82-php-zip php82-php-bcmath php82-php-redis \
                mariadb-server redis \
                poppler-utils ghostscript \
                wkhtmltopdf \
                tesseract tesseract-langpack-fra \
                ImageMagick ImageMagick-devel \
                python3 python3-pip python3-devel \
                java-11-openjdk \
                git curl wget unzip
        fi
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        if command_exists brew; then
            brew update
            brew install \
                php@8.2 mariadb redis \
                poppler ghostscript \
                wkhtmltopdf \
                tesseract tesseract-lang \
                imagemagick \
                python3 \
                openjdk
        fi
    fi
    
    echo -e "${GREEN}✓ System dependencies installed${NC}"
}

# Function to install Python packages
install_python_packages() {
    echo -e "${YELLOW}Installing Python packages for PDF processing...${NC}"
    
    # Install PyMuPDF and related packages
    pip3 install --break-system-packages PyMuPDF Pillow reportlab 2>/dev/null || \
    pip3 install --user PyMuPDF Pillow reportlab
    
    # Install table extraction packages
    pip3 install --break-system-packages tabula-py pandas pdfplumber openpyxl pytesseract 2>/dev/null || \
    pip3 install --user tabula-py pandas pdfplumber openpyxl pytesseract
    
    # Install image processing packages
    pip3 install --break-system-packages opencv-python-headless 2>/dev/null || \
    pip3 install --user opencv-python-headless
    
    echo -e "${GREEN}✓ Python packages installed${NC}"
}

# Function to setup database
setup_database() {
    echo -e "${YELLOW}Setting up database...${NC}"
    
    # Check if .env exists
    if [ ! -f .env ]; then
        cp .env.example .env
        echo -e "${GREEN}✓ Created .env file${NC}"
    fi
    
    # Generate application key
    php artisan key:generate --force
    
    # Run migrations
    php artisan migrate --force
    
    echo -e "${GREEN}✓ Database configured${NC}"
}

# Function to install composer dependencies
install_composer_deps() {
    echo -e "${YELLOW}Installing Composer dependencies...${NC}"
    
    if ! command_exists composer; then
        # Install Composer
        curl -sS https://getcomposer.org/installer | php
        sudo mv composer.phar /usr/local/bin/composer
    fi
    
    # Remove unused packages
    composer remove laravel/sail laravel/pail --no-interaction 2>/dev/null || true
    
    # Install dependencies
    composer install --optimize-autoloader --no-dev
    
    echo -e "${GREEN}✓ Composer dependencies installed${NC}"
}

# Function to install npm dependencies
install_npm_deps() {
    echo -e "${YELLOW}Installing NPM dependencies...${NC}"
    
    if ! command_exists npm; then
        # Install Node.js and npm
        curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
        sudo apt-get install -y nodejs
    fi
    
    # Clean install
    rm -rf node_modules package-lock.json
    npm install --production
    
    # Build assets
    npm run build
    
    echo -e "${GREEN}✓ NPM dependencies installed and built${NC}"
}

# Function to set permissions
set_permissions() {
    echo -e "${YELLOW}Setting permissions...${NC}"
    
    # Storage and cache permissions
    chmod -R 775 storage bootstrap/cache
    
    # Set owner (adjust based on your web server user)
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        sudo chown -R www-data:www-data storage bootstrap/cache
    fi
    
    # Create storage link
    php artisan storage:link --force
    
    echo -e "${GREEN}✓ Permissions configured${NC}"
}

# Function to optimize application
optimize_app() {
    echo -e "${YELLOW}Optimizing application...${NC}"
    
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    
    echo -e "${GREEN}✓ Application optimized${NC}"
}

# Function to create supervisor config
create_supervisor_config() {
    echo -e "${YELLOW}Creating supervisor configuration...${NC}"
    
    APP_PATH=$(pwd)
    
    cat > gigapdf-supervisor.conf << EOF
[program:gigapdf-horizon]
process_name=%(program_name)s
command=php ${APP_PATH}/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=${APP_PATH}/storage/logs/horizon.log
stopwaitsecs=3600

[program:gigapdf-queue]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_PATH}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=${APP_PATH}/storage/logs/queue.log
stopwaitsecs=3600
EOF
    
    echo -e "${GREEN}✓ Supervisor configuration created: gigapdf-supervisor.conf${NC}"
    echo -e "${YELLOW}To install: sudo cp gigapdf-supervisor.conf /etc/supervisor/conf.d/${NC}"
}

# Main installation process
main() {
    # Check if running as root
    if [ "$EUID" -eq 0 ]; then 
        echo -e "${RED}Please don't run this script as root${NC}"
        exit 1
    fi
    
    # Parse arguments
    SKIP_SYSTEM_DEPS=false
    SKIP_PYTHON=false
    SKIP_NPM=false
    CLEAN_INSTALL=false
    
    for arg in "$@"; do
        case $arg in
            --skip-system)
                SKIP_SYSTEM_DEPS=true
                ;;
            --skip-python)
                SKIP_PYTHON=true
                ;;
            --skip-npm)
                SKIP_NPM=true
                ;;
            --clean)
                CLEAN_INSTALL=true
                ;;
            --help)
                echo "Usage: ./install.sh [options]"
                echo "Options:"
                echo "  --skip-system   Skip system dependencies installation"
                echo "  --skip-python   Skip Python packages installation"
                echo "  --skip-npm      Skip NPM packages installation"
                echo "  --clean         Clean installation (removes vendor, node_modules)"
                echo "  --help          Show this help message"
                exit 0
                ;;
        esac
    done
    
    # Clean installation if requested
    if [ "$CLEAN_INSTALL" = true ]; then
        echo -e "${YELLOW}Performing clean installation...${NC}"
        rm -rf vendor node_modules package-lock.json composer.lock
    fi
    
    # Install system dependencies
    if [ "$SKIP_SYSTEM_DEPS" = false ]; then
        install_system_deps
    fi
    
    # Install Python packages
    if [ "$SKIP_PYTHON" = false ]; then
        install_python_packages
    fi
    
    # Install Composer dependencies
    install_composer_deps
    
    # Install NPM dependencies
    if [ "$SKIP_NPM" = false ]; then
        install_npm_deps
    fi
    
    # Setup database
    setup_database
    
    # Set permissions
    set_permissions
    
    # Optimize application
    optimize_app
    
    # Create supervisor config
    create_supervisor_config
    
    # Final message
    echo -e "${GREEN}"
    echo "╔══════════════════════════════════════════════════════════╗"
    echo "║          INSTALLATION COMPLETED SUCCESSFULLY              ║"
    echo "╚══════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    echo "🚀 Giga-PDF is now installed!"
    echo ""
    echo "📋 Next steps:"
    echo "  1. Configure your .env file with database credentials"
    echo "  2. Run: php artisan gigapdf:install --with-demo"
    echo "  3. Install supervisor config: sudo cp gigapdf-supervisor.conf /etc/supervisor/conf.d/"
    echo "  4. Start services:"
    echo "     - php artisan serve"
    echo "     - php artisan horizon"
    echo "     - php artisan queue:work"
    echo "  5. Access the application at http://localhost:8000"
    echo ""
    echo "📚 Documentation: https://github.com/ronylicha/giga-pdf"
}

# Run main installation
main "$@"
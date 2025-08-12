#!/bin/bash

# Giga-PDF Complete Installation Script
# This script handles the complete installation of Giga-PDF application

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PROJECT_DIR="/var/www/html/giga-pdf"
WEB_USER="www-data"
WEB_GROUP="www-data"

# Functions
print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

check_command() {
    if command -v $1 &> /dev/null; then
        print_success "$1 is installed"
        return 0
    else
        print_error "$1 is not installed"
        return 1
    fi
}

# Header
echo "======================================"
echo "   Giga-PDF Installation Script"
echo "======================================"
echo ""

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   print_error "This script must be run as root"
   exit 1
fi

# Step 1: Check Prerequisites
print_info "Checking prerequisites..."

MISSING_DEPS=0

# Check PHP
if check_command php; then
    PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
    if [[ $(echo "$PHP_VERSION >= 8.4" | bc) -eq 1 ]]; then
        print_success "PHP version $PHP_VERSION meets requirements"
    else
        print_error "PHP version $PHP_VERSION is too old. Required: 8.4+"
        MISSING_DEPS=1
    fi
else
    MISSING_DEPS=1
fi

# Check required commands
for cmd in composer npm mysql redis-cli; do
    check_command $cmd || MISSING_DEPS=1
done

# Check required PHP extensions
print_info "Checking PHP extensions..."
for ext in gd imagick zip redis pdo_mysql mbstring xml curl bcmath; do
    if php -m | grep -q "^$ext$"; then
        print_success "PHP extension $ext is installed"
    else
        print_error "PHP extension $ext is missing"
        MISSING_DEPS=1
    fi
done

# Check optional dependencies
print_info "Checking optional dependencies..."
check_command libreoffice || print_warning "LibreOffice not found (required for document conversions)"
check_command tesseract || print_warning "Tesseract not found (required for OCR)"
check_command qpdf || print_warning "qpdf not found (RECOMMENDED for PDF password removal)"
check_command gs || print_warning "Ghostscript not found (RECOMMENDED for forced PDF unlocking)"
check_command pdftk || print_warning "pdftk not found (fallback for PDF operations)"
check_command pdftotext || print_warning "pdftotext not found (for PDF text extraction)"
check_command pdftohtml || print_warning "pdftohtml not found (for PDF to HTML conversion)"
check_command wkhtmltopdf || print_warning "wkhtmltopdf not found (for HTML to PDF conversion)"
check_command convert || print_warning "ImageMagick not found (for image processing)"
check_command python3 || print_warning "Python3 not found (for advanced PDF features)"
check_command pip3 || print_warning "pip3 not found (for Python packages)"

if [ $MISSING_DEPS -eq 1 ]; then
    print_error "Missing required dependencies. Please install them first."
    exit 1
fi

# Step 1b: Install optional PDF tools if missing
print_info "Checking and installing PDF tools..."

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    OS="unknown"
fi

# Install qpdf if missing (essential for password removal)
if ! command -v qpdf &> /dev/null; then
    print_warning "Installing qpdf for PDF password removal..."
    case $OS in
        ubuntu|debian)
            apt-get update && apt-get install -y qpdf
            ;;
        centos|rhel|fedora)
            yum install -y qpdf
            ;;
        *)
            print_warning "Please install qpdf manually for PDF password removal"
            ;;
    esac
fi

# Install ghostscript if missing (essential for forced password removal)
if ! command -v gs &> /dev/null; then
    print_warning "Installing ghostscript for forced PDF unlocking..."
    case $OS in
        ubuntu|debian)
            apt-get install -y ghostscript
            ;;
        centos|rhel|fedora)
            yum install -y ghostscript
            ;;
        *)
            print_warning "Please install ghostscript manually for forced PDF unlocking"
            ;;
    esac
fi

# Install poppler-utils if missing
if ! command -v pdftotext &> /dev/null; then
    print_warning "Installing poppler-utils for PDF text extraction..."
    case $OS in
        ubuntu|debian)
            apt-get install -y poppler-utils
            ;;
        centos|rhel|fedora)
            yum install -y poppler-utils
            ;;
        *)
            print_warning "Please install poppler-utils manually"
            ;;
    esac
fi

# Install Python packages for PDF manipulation
if command -v python3 &> /dev/null && command -v pip3 &> /dev/null; then
    print_info "Installing Python PDF libraries..."
    pip3 install --break-system-packages pypdf PyPDF2 PyMuPDF beautifulsoup4 lxml 2>/dev/null || \
    pip3 install --user pypdf PyPDF2 PyMuPDF beautifulsoup4 lxml 2>/dev/null || \
    print_warning "Could not install Python PDF libraries automatically"
fi

# Step 2: Navigate to project directory
cd $PROJECT_DIR || {
    print_error "Project directory not found: $PROJECT_DIR"
    exit 1
}

# Step 3: Install Composer dependencies
print_info "Installing PHP dependencies..."
sudo -u $WEB_USER composer install --optimize-autoloader --no-dev || {
    print_error "Failed to install composer dependencies"
    exit 1
}
print_success "PHP dependencies installed"

# Step 4: Install NPM dependencies and build assets
print_info "Installing JavaScript dependencies..."
npm install || {
    print_error "Failed to install npm dependencies"
    exit 1
}
print_success "JavaScript dependencies installed"

print_info "Building frontend assets..."
npm run build || {
    print_error "Failed to build assets"
    exit 1
}
print_success "Frontend assets built"

# Step 5: Setup environment file
if [ ! -f .env ]; then
    print_info "Creating .env file..."
    cp .env.example .env
    print_success ".env file created"
else
    print_warning ".env file already exists"
fi

# Step 6: Generate application key
print_info "Generating application key..."
php artisan key:generate --force
print_success "Application key generated"

# Step 7: Configure database
print_info "Configuring database..."
read -p "Enter MySQL database name [gigapdf]: " DB_NAME
DB_NAME=${DB_NAME:-gigapdf}

read -p "Enter MySQL username [gigapdf_user]: " DB_USER
DB_USER=${DB_USER:-gigapdf_user}

read -sp "Enter MySQL password: " DB_PASS
echo ""

# Update .env file
sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env

# Test database connection
php artisan db:show &> /dev/null && print_success "Database connection successful" || {
    print_error "Failed to connect to database. Please check your credentials."
    exit 1
}

# Step 8: Run migrations
print_info "Running database migrations..."
php artisan migrate --force || {
    print_error "Failed to run migrations"
    exit 1
}
print_success "Database migrations completed"

# Step 9: Seed database
print_info "Seeding database..."
php artisan db:seed --class=ProductionSeeder --force || {
    print_warning "Failed to seed database (may already be seeded)"
}

# Step 10: Create storage directories
print_info "Creating storage directories..."
mkdir -p storage/app/public/{documents,conversions,thumbnails,temp}
mkdir -p storage/app/private/{documents,conversions,thumbnails,temp}
mkdir -p storage/backups
mkdir -p storage/framework/{sessions,views,cache}
mkdir -p storage/logs
print_success "Storage directories created"

# Step 11: Create storage link
print_info "Creating storage link..."
php artisan storage:link --force
print_success "Storage link created"

# Step 12: Set permissions
print_info "Setting file permissions..."
chown -R $WEB_USER:$WEB_GROUP $PROJECT_DIR
chmod -R 755 $PROJECT_DIR
chmod -R 775 storage bootstrap/cache
chmod -R 775 storage/app/public
chmod -R 775 storage/app/private
print_success "File permissions set"

# Step 13: Configure Laravel Horizon
print_info "Configuring Laravel Horizon..."
php artisan horizon:publish 2>/dev/null || true

# Step 14: Configure Laravel Reverb (WebSockets)
print_info "Configuring Laravel Reverb..."
php artisan reverb:install --quiet 2>/dev/null || true

# Step 15: Setup cron job
print_info "Setting up cron job..."
CRON_JOB="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u $WEB_USER -l 2>/dev/null | grep -F "$CRON_JOB") || (crontab -u $WEB_USER -l 2>/dev/null; echo "$CRON_JOB") | crontab -u $WEB_USER -
print_success "Cron job configured"

# Step 16: Setup Supervisor for queues
print_info "Setting up Supervisor..."
SUPERVISOR_CONF="/etc/supervisor/conf.d/giga-pdf.conf"

if [ ! -f $SUPERVISOR_CONF ]; then
    cat > $SUPERVISOR_CONF << 'EOF'
[program:giga-pdf-horizon]
process_name=%(program_name)s
command=php /var/www/html/giga-pdf/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/giga-pdf/storage/logs/horizon.log
stopwaitsecs=3600

[program:giga-pdf-reverb]
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
    supervisorctl start giga-pdf:*
    print_success "Supervisor configured and started"
else
    print_warning "Supervisor configuration already exists"
    supervisorctl restart giga-pdf:*
fi

# Step 17: Optimize application
print_info "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
print_success "Application optimized"

# Step 18: Run Giga-PDF installation command
print_info "Running Giga-PDF installation command..."
php artisan gigapdf:install --force --with-demo || {
    print_warning "Giga-PDF installation command failed or already installed"
}

# Step 18b: Create first tenant and admin user (if not created by install command)
if ! php artisan tenant:list 2>/dev/null | grep -q "tenants found"; then
    print_info "Creating first tenant and admin user..."
    read -p "Enter organization name: " ORG_NAME
    read -p "Enter admin email: " ADMIN_EMAIL
    read -sp "Enter admin password: " ADMIN_PASS
    echo ""

    php artisan tenant:create "$ORG_NAME" <<EOF
$ADMIN_EMAIL
$ADMIN_PASS
$ADMIN_PASS
EOF

    print_success "Tenant and admin user created"
fi

# Step 19: Install fonts for PDF operations
print_info "Installing fonts..."
php artisan pdf:install-fonts 2>/dev/null || print_warning "Could not install fonts"

# Step 20: Test installation
print_info "Running installation tests..."

# Test database connection
php artisan db:show &> /dev/null && print_success "Database: OK" || print_error "Database: FAILED"

# Test Redis connection
redis-cli ping &> /dev/null && print_success "Redis: OK" || print_error "Redis: FAILED"

# Test queue worker
php artisan queue:work --stop-when-empty &> /dev/null && print_success "Queue: OK" || print_error "Queue: FAILED"

# Test storage
touch storage/app/test.txt && rm storage/app/test.txt && print_success "Storage: OK" || print_error "Storage: FAILED"

# Step 21: Display summary
echo ""
echo "======================================"
echo "   Installation Complete!"
echo "======================================"
echo ""
print_success "Giga-PDF has been successfully installed!"
echo ""
echo "Next steps:"
echo "1. Configure your web server (Nginx/Apache) to point to: $PROJECT_DIR/public"
echo "2. Set up SSL certificate for HTTPS"
echo "3. Configure your mail settings in .env file"
echo "4. Access the application and login with:"
echo "   Email: $ADMIN_EMAIL"
echo "   Password: [the password you entered]"
echo ""
echo "Useful commands:"
echo "  - Monitor queues: php artisan horizon"
echo "  - List tenants: php artisan tenant:list"
echo "  - Create backup: php artisan backup:run"
echo "  - Monitor storage: php artisan monitor:storage-usage"
echo ""
print_info "Documentation: https://github.com/ronylicha/Giga-Pdf"
echo ""
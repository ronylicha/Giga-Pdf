#!/bin/bash

# Ploi Permission Fix Script for Giga-PDF
# This script fixes ownership and permissions issues
# Usage: bash .ploi/fix-permissions.sh [site_directory]

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
SITE_DIR="${1:-/home/ploi/$(basename $(pwd))}"
WEB_USER="ploi"
WEB_GROUP="ploi"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}    Giga-PDF Permission Fix for Ploi           ${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Check if running as root or ploi user with sudo
if [ "$EUID" -ne 0 ] && [ "$USER" != "ploi" ]; then
    echo -e "${RED}✗${NC} This script must be run as root or ploi user with sudo"
    exit 1
fi

cd $SITE_DIR || {
    echo -e "${RED}✗${NC} Directory $SITE_DIR not found"
    exit 1
}

echo -e "${YELLOW}Fixing permissions for:${NC} $SITE_DIR"
echo -e "${YELLOW}User/Group:${NC} $WEB_USER:$WEB_GROUP"
echo ""

# Function to fix permissions
fix_permissions() {
    local path=$1
    local perms=$2
    local desc=$3
    
    if [ -e "$path" ]; then
        chmod -R $perms $path
        chown -R $WEB_USER:$WEB_GROUP $path
        echo -e "${GREEN}✓${NC} Fixed: $desc ($perms)"
    else
        echo -e "${YELLOW}→${NC} Skipped: $desc (not found)"
    fi
}

echo -e "${BLUE}Step 1: Fixing ownership...${NC}"
chown -R $WEB_USER:$WEB_GROUP $SITE_DIR
echo -e "${GREEN}✓${NC} All files owned by $WEB_USER:$WEB_GROUP"

echo ""
echo -e "${BLUE}Step 2: Setting base permissions...${NC}"
find $SITE_DIR -type d -exec chmod 755 {} \;
find $SITE_DIR -type f -exec chmod 644 {} \;
echo -e "${GREEN}✓${NC} Base permissions set (755 for dirs, 644 for files)"

echo ""
echo -e "${BLUE}Step 3: Fixing writable directories...${NC}"
fix_permissions "storage" "775" "storage/*"
fix_permissions "bootstrap/cache" "775" "bootstrap/cache"
fix_permissions "public/uploads" "775" "public/uploads"
fix_permissions "public/downloads" "775" "public/downloads"

echo ""
echo -e "${BLUE}Step 4: Fixing LibreOffice directories...${NC}"
fix_permissions "storage/app/libreoffice" "777" "storage/app/libreoffice"
fix_permissions "storage/app/conversions" "777" "storage/app/conversions"
fix_permissions "storage/app/temp" "777" "storage/app/temp"

echo ""
echo -e "${BLUE}Step 5: Fixing sensitive directories...${NC}"
fix_permissions "storage/app/private" "750" "storage/app/private"
fix_permissions "resources/certificates" "750" "resources/certificates"
fix_permissions ".env" "600" ".env file"

echo ""
echo -e "${BLUE}Step 6: Fixing executable files...${NC}"

# Make artisan executable
if [ -f "artisan" ]; then
    chmod +x artisan
    echo -e "${GREEN}✓${NC} artisan is executable"
fi

# Make all shell scripts executable
find . -name "*.sh" -type f -exec chmod +x {} \;
echo -e "${GREEN}✓${NC} All .sh scripts are executable"

# Make composer and npm scripts executable
for file in composer.phar vendor/bin/* node_modules/.bin/*; do
    if [ -f "$file" ]; then
        chmod +x "$file"
    fi
done
echo -e "${GREEN}✓${NC} Vendor binaries are executable"

echo ""
echo -e "${BLUE}Step 7: Clearing caches...${NC}"

# Clear Laravel caches (run as ploi user)
sudo -u $WEB_USER php artisan cache:clear 2>/dev/null || echo -e "${YELLOW}→${NC} Cache already clear"
sudo -u $WEB_USER php artisan config:clear 2>/dev/null || echo -e "${YELLOW}→${NC} Config cache clear"
sudo -u $WEB_USER php artisan view:clear 2>/dev/null || echo -e "${YELLOW}→${NC} View cache clear"

echo ""
echo -e "${BLUE}Step 8: Verifying critical paths...${NC}"

# Check critical paths
critical_paths=(
    "storage/app"
    "storage/framework"
    "storage/logs"
    "bootstrap/cache"
    "storage/app/libreoffice"
    "storage/app/conversions"
)

all_good=true
for path in "${critical_paths[@]}"; do
    if [ -w "$path" ]; then
        echo -e "${GREEN}✓${NC} Writable: $path"
    else
        echo -e "${RED}✗${NC} Not writable: $path"
        all_good=false
    fi
done

echo ""
if [ "$all_good" = true ]; then
    echo -e "${GREEN}================================================${NC}"
    echo -e "${GREEN}    Permissions fixed successfully!             ${NC}"
    echo -e "${GREEN}================================================${NC}"
else
    echo -e "${YELLOW}================================================${NC}"
    echo -e "${YELLOW}    Permissions fixed with warnings             ${NC}"
    echo -e "${YELLOW}================================================${NC}"
    echo ""
    echo -e "${YELLOW}Some paths may need manual attention.${NC}"
fi

echo ""
echo "Quick checks:"
echo "• Storage writable: $([ -w storage ] && echo '✓' || echo '✗')"
echo "• LibreOffice ready: $([ -w storage/app/libreoffice ] && echo '✓' || echo '✗')"
echo "• Conversions ready: $([ -w storage/app/conversions ] && echo '✓' || echo '✗')"
echo "• .env protected: $([ -f .env ] && [ ! -w .env ] 2>/dev/null || echo '✓')"
echo ""
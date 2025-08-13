#!/bin/bash

# Ploi Setup Check Script for Giga-PDF
# This script checks if all directories and permissions are correctly configured
# Usage: bash .ploi/check-setup.sh [site_directory]

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# Configuration
SITE_DIR="${1:-/home/ploi/$(basename $(pwd))}"
WEB_USER="ploi"
WEB_GROUP="ploi"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}    Giga-PDF Setup Check for Ploi              ${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo -e "${MAGENTA}Site Directory:${NC} $SITE_DIR"
echo -e "${MAGENTA}Expected User:${NC} $WEB_USER:$WEB_GROUP"
echo ""

cd $SITE_DIR 2>/dev/null || {
    echo -e "${RED}✗${NC} Cannot access directory: $SITE_DIR"
    exit 1
}

# Function to check directory
check_dir() {
    local dir=$1
    local required=$2
    
    if [ -d "$dir" ]; then
        if [ -w "$dir" ]; then
            echo -e "${GREEN}✓${NC} $dir (writable)"
        else
            echo -e "${YELLOW}⚠${NC} $dir (not writable)"
            [ "$required" = "required" ] && return 1
        fi
    else
        echo -e "${RED}✗${NC} $dir (missing)"
        [ "$required" = "required" ] && return 1
    fi
    return 0
}

# Function to check file
check_file() {
    local file=$1
    local perms=$2
    
    if [ -f "$file" ]; then
        actual_perms=$(stat -c "%a" "$file")
        if [ "$actual_perms" = "$perms" ]; then
            echo -e "${GREEN}✓${NC} $file ($perms)"
        else
            echo -e "${YELLOW}⚠${NC} $file (expected $perms, found $actual_perms)"
        fi
    else
        echo -e "${YELLOW}→${NC} $file (not found)"
    fi
}

# Function to check ownership
check_ownership() {
    local path=$1
    if [ -e "$path" ]; then
        owner=$(stat -c "%U:%G" "$path")
        if [ "$owner" = "$WEB_USER:$WEB_GROUP" ]; then
            return 0
        else
            return 1
        fi
    fi
    return 1
}

echo -e "${BLUE}1. Checking Critical Directories${NC}"
echo "--------------------------------"
errors=0

# Critical directories
critical_dirs=(
    "storage"
    "storage/app"
    "storage/app/conversions"
    "storage/app/libreoffice"
    "storage/app/libreoffice/cache"
    "storage/app/libreoffice/config"
    "storage/app/libreoffice/temp"
    "storage/framework"
    "storage/framework/cache"
    "storage/framework/sessions"
    "storage/framework/views"
    "storage/logs"
    "bootstrap/cache"
)

for dir in "${critical_dirs[@]}"; do
    check_dir "$dir" "required" || ((errors++))
done

echo ""
echo -e "${BLUE}2. Checking Public/Private Storage${NC}"
echo "-----------------------------------"

# Storage directories
storage_dirs=(
    "storage/app/public"
    "storage/app/public/documents"
    "storage/app/public/conversions"
    "storage/app/public/thumbnails"
    "storage/app/public/temp"
    "storage/app/private"
    "storage/app/private/documents"
    "storage/app/private/conversions"
    "storage/app/private/certificates"
)

for dir in "${storage_dirs[@]}"; do
    check_dir "$dir" "optional"
done

echo ""
echo -e "${BLUE}3. Checking File Permissions${NC}"
echo "----------------------------"

# Check specific files
check_file ".env" "600"
check_file "artisan" "755"

echo ""
echo -e "${BLUE}4. Checking Ownership${NC}"
echo "---------------------"

# Check ownership of key directories
ownership_ok=true
for dir in storage bootstrap/cache; do
    if check_ownership "$dir"; then
        echo -e "${GREEN}✓${NC} $dir owned by $WEB_USER:$WEB_GROUP"
    else
        echo -e "${YELLOW}⚠${NC} $dir has incorrect ownership"
        ownership_ok=false
    fi
done

echo ""
echo -e "${BLUE}5. Checking LibreOffice Setup${NC}"
echo "-----------------------------"

# Check LibreOffice
if command -v libreoffice &> /dev/null; then
    echo -e "${GREEN}✓${NC} LibreOffice installed"
    
    # Check if LibreOffice directories are writable
    lo_ready=true
    for dir in storage/app/libreoffice/{cache,config,temp}; do
        if [ -w "$dir" ]; then
            echo -e "${GREEN}✓${NC} $dir is writable"
        else
            echo -e "${YELLOW}⚠${NC} $dir is not writable"
            lo_ready=false
        fi
    done
else
    echo -e "${YELLOW}⚠${NC} LibreOffice not installed"
    lo_ready=false
fi

echo ""
echo -e "${BLUE}6. Checking Storage Link${NC}"
echo "------------------------"

if [ -L "public/storage" ]; then
    target=$(readlink public/storage)
    if [[ "$target" == *"storage/app/public"* ]]; then
        echo -e "${GREEN}✓${NC} Storage link exists and is correct"
    else
        echo -e "${YELLOW}⚠${NC} Storage link points to wrong location: $target"
    fi
else
    echo -e "${YELLOW}⚠${NC} Storage link missing (run: php artisan storage:link)"
fi

echo ""
echo -e "${BLUE}7. Checking Environment${NC}"
echo "-----------------------"

# Check .env file
if [ -f ".env" ]; then
    echo -e "${GREEN}✓${NC} .env file exists"
    
    # Check key variables (without exposing values)
    required_vars=("APP_KEY" "DB_DATABASE" "DB_USERNAME")
    for var in "${required_vars[@]}"; do
        if grep -q "^${var}=" .env && ! grep -q "^${var}=$" .env; then
            echo -e "${GREEN}✓${NC} $var is set"
        else
            echo -e "${YELLOW}⚠${NC} $var is not set"
        fi
    done
else
    echo -e "${RED}✗${NC} .env file missing"
fi

echo ""
echo -e "${BLUE}8. Checking PHP Requirements${NC}"
echo "----------------------------"

# Check PHP version
php_version=$(php -v | head -n 1 | grep -oP '\d+\.\d+')
if [[ $(echo "$php_version >= 8.4" | bc) -eq 1 ]]; then
    echo -e "${GREEN}✓${NC} PHP version $php_version meets requirements"
else
    echo -e "${YELLOW}⚠${NC} PHP version $php_version (8.4+ required)"
fi

# Check PHP extensions
extensions=("pdo_mysql" "gd" "imagick" "zip" "redis" "mbstring" "xml" "curl")
for ext in "${extensions[@]}"; do
    if php -m | grep -q "^$ext$"; then
        echo -e "${GREEN}✓${NC} PHP extension: $ext"
    else
        echo -e "${YELLOW}⚠${NC} PHP extension missing: $ext"
    fi
done

echo ""
echo -e "${BLUE}9. Quick Write Tests${NC}"
echo "--------------------"

# Test write to critical directories
test_dirs=("storage/app" "storage/logs" "storage/app/conversions")
for dir in "${test_dirs[@]}"; do
    if [ -d "$dir" ]; then
        test_file="$dir/.write_test_$$"
        if touch "$test_file" 2>/dev/null; then
            rm "$test_file"
            echo -e "${GREEN}✓${NC} Can write to $dir"
        else
            echo -e "${RED}✗${NC} Cannot write to $dir"
        fi
    fi
done

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}                   SUMMARY                      ${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Calculate overall status
if [ $errors -eq 0 ] && [ "$ownership_ok" = true ] && [ "$lo_ready" = true ]; then
    echo -e "${GREEN}✅ All checks passed! Your setup is ready.${NC}"
    exit_code=0
elif [ $errors -eq 0 ]; then
    echo -e "${YELLOW}⚠️  Setup is functional but has minor issues.${NC}"
    echo ""
    echo "Recommended actions:"
    [ "$ownership_ok" = false ] && echo "• Fix ownership: sudo chown -R $WEB_USER:$WEB_GROUP $SITE_DIR"
    [ "$lo_ready" = false ] && echo "• Install/configure LibreOffice for document conversions"
    exit_code=0
else
    echo -e "${RED}❌ Setup has critical issues that need fixing.${NC}"
    echo ""
    echo "Required actions:"
    echo "• Run: bash .ploi/setup-directories.sh $SITE_DIR"
    echo "• Run: bash .ploi/fix-permissions.sh $SITE_DIR"
    exit_code=1
fi

echo ""
echo "For detailed setup, run:"
echo "• bash .ploi/setup-directories.sh  - Create all directories"
echo "• bash .ploi/fix-permissions.sh    - Fix all permissions"
echo ""

exit $exit_code
#!/bin/bash

# Ploi Directory Setup Script for Giga-PDF
# This script creates all necessary directories and sets proper permissions
# Usage: bash .ploi/setup-directories.sh [site_directory]

set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SITE_DIR="${1:-/home/ploi/$(basename $(pwd))}"
WEB_USER="ploi"
WEB_GROUP="ploi"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}    Giga-PDF Directory Setup for Ploi          ${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo -e "${YELLOW}Site Directory:${NC} $SITE_DIR"
echo -e "${YELLOW}User/Group:${NC} $WEB_USER:$WEB_GROUP"
echo ""

# Navigate to site directory
cd $SITE_DIR || {
    echo -e "${YELLOW}⚠ Directory $SITE_DIR not found${NC}"
    exit 1
}

echo -e "${BLUE}Creating storage directories...${NC}"

# Main storage directories
directories=(
    "storage/app/public"
    "storage/app/public/documents"
    "storage/app/public/conversions"
    "storage/app/public/thumbnails"
    "storage/app/public/temp"
    "storage/app/public/exports"
    "storage/app/public/avatars"
    "storage/app/private"
    "storage/app/private/documents"
    "storage/app/private/conversions"
    "storage/app/private/thumbnails"
    "storage/app/private/temp"
    "storage/app/private/certificates"
    "storage/app/private/backups"
    "storage/app/conversions"
    "storage/app/temp"
    "storage/app/libreoffice"
    "storage/app/libreoffice/cache"
    "storage/app/libreoffice/config"
    "storage/app/libreoffice/temp"
    "storage/framework"
    "storage/framework/sessions"
    "storage/framework/views"
    "storage/framework/cache"
    "storage/framework/cache/data"
    "storage/framework/testing"
    "storage/logs"
    "storage/backups"
    "storage/backups/database"
    "storage/backups/files"
    "bootstrap/cache"
    "database/backups"
    "public/uploads"
    "public/downloads"
    "resources/fonts"
    "resources/certificates"
)

# Create each directory
for dir in "${directories[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo -e "${GREEN}✓${NC} Created: $dir"
    else
        echo -e "${YELLOW}→${NC} Exists: $dir"
    fi
done

echo ""
echo -e "${BLUE}Setting ownership...${NC}"

# Set ownership for entire project
chown -R $WEB_USER:$WEB_GROUP $SITE_DIR
echo -e "${GREEN}✓${NC} Ownership set to $WEB_USER:$WEB_GROUP"

echo ""
echo -e "${BLUE}Setting permissions...${NC}"

# Base permissions
chmod -R 755 $SITE_DIR
echo -e "${GREEN}✓${NC} Base permissions: 755"

# Writable directories
writable_dirs=(
    "storage"
    "bootstrap/cache"
    "public/uploads"
    "public/downloads"
)

for dir in "${writable_dirs[@]}"; do
    if [ -d "$dir" ]; then
        chmod -R 775 $dir
        echo -e "${GREEN}✓${NC} Writable (775): $dir"
    fi
done

# Special permissions for sensitive directories
sensitive_dirs=(
    "storage/app/private"
    "storage/app/private/certificates"
    "storage/app/private/backups"
    "resources/certificates"
)

for dir in "${sensitive_dirs[@]}"; do
    if [ -d "$dir" ]; then
        chmod -R 750 $dir
        echo -e "${GREEN}✓${NC} Restricted (750): $dir"
    fi
done

# Ensure LibreOffice directories are writable
libreoffice_dirs=(
    "storage/app/libreoffice"
    "storage/app/libreoffice/cache"
    "storage/app/libreoffice/config"
    "storage/app/libreoffice/temp"
    "storage/app/conversions"
)

for dir in "${libreoffice_dirs[@]}"; do
    if [ -d "$dir" ]; then
        chmod -R 777 $dir
        echo -e "${GREEN}✓${NC} LibreOffice writable (777): $dir"
    fi
done

echo ""
echo -e "${BLUE}Creating .gitignore files...${NC}"

# Add .gitignore to directories that should be empty in git
gitignore_dirs=(
    "storage/app/public/documents"
    "storage/app/public/conversions"
    "storage/app/public/thumbnails"
    "storage/app/public/temp"
    "storage/app/public/exports"
    "storage/app/public/avatars"
    "storage/app/private/documents"
    "storage/app/private/conversions"
    "storage/app/private/thumbnails"
    "storage/app/private/temp"
    "storage/app/private/certificates"
    "storage/app/private/backups"
    "storage/app/conversions"
    "storage/app/temp"
    "storage/app/libreoffice/cache"
    "storage/app/libreoffice/config"
    "storage/app/libreoffice/temp"
    "storage/framework/sessions"
    "storage/framework/views"
    "storage/framework/cache/data"
    "storage/framework/testing"
    "storage/backups"
    "database/backups"
    "public/uploads"
    "public/downloads"
)

for dir in "${gitignore_dirs[@]}"; do
    if [ -d "$dir" ] && [ ! -f "$dir/.gitignore" ]; then
        echo "*" > "$dir/.gitignore"
        echo "!.gitignore" >> "$dir/.gitignore"
        echo -e "${GREEN}✓${NC} .gitignore: $dir"
    fi
done

echo ""
echo -e "${BLUE}Checking storage link...${NC}"

# Create storage link if it doesn't exist
if [ ! -L "public/storage" ]; then
    # Remove directory if it exists but is not a symlink
    if [ -d "public/storage" ]; then
        rm -rf public/storage
    fi
    
    # Create the symlink using PHP artisan command
    php artisan storage:link --force
    echo -e "${GREEN}✓${NC} Storage link created"
else
    echo -e "${YELLOW}→${NC} Storage link already exists"
fi

echo ""
echo -e "${BLUE}Verifying setup...${NC}"

# Test write permissions
test_file="storage/app/test_write.txt"
if echo "test" > $test_file 2>/dev/null; then
    rm $test_file
    echo -e "${GREEN}✓${NC} Write permissions: OK"
else
    echo -e "${YELLOW}⚠${NC} Write permissions: FAILED"
fi

# Check LibreOffice directories
if [ -d "storage/app/libreoffice" ] && [ -w "storage/app/libreoffice" ]; then
    echo -e "${GREEN}✓${NC} LibreOffice directories: OK"
else
    echo -e "${YELLOW}⚠${NC} LibreOffice directories: FAILED"
fi

# Check conversion directory
if [ -d "storage/app/conversions" ] && [ -w "storage/app/conversions" ]; then
    echo -e "${GREEN}✓${NC} Conversion directory: OK"
else
    echo -e "${YELLOW}⚠${NC} Conversion directory: FAILED"
fi

# Final ownership check (in case any commands created files with wrong ownership)
chown -R $WEB_USER:$WEB_GROUP $SITE_DIR

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}    Directory setup completed successfully!     ${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "Summary:"
echo "• All required directories created"
echo "• Ownership set to $WEB_USER:$WEB_GROUP"
echo "• Permissions configured correctly"
echo "• Storage link verified"
echo "• LibreOffice directories prepared"
echo ""
echo "You can now proceed with the application deployment."
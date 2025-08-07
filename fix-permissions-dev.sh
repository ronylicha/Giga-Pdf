#!/bin/bash

# Script rapide de permissions pour l'environnement de développement
# Usage: ./fix-permissions-dev.sh

echo "=' Correction rapide des permissions (DEV)..."

APP_PATH="/var/www/html/giga-pdf"

# Permissions générales
echo "=Á Application des permissions de base..."
sudo chown -R $USER:$USER $APP_PATH
sudo chmod -R 755 $APP_PATH

# Permissions spéciales pour storage et cache
echo "=¾ Configuration storage et cache..."
sudo chmod -R 777 $APP_PATH/storage
sudo chmod -R 777 $APP_PATH/bootstrap/cache

# Permissions pour les builds
if [ -d "$APP_PATH/public/build" ]; then
    echo "<× Configuration build..."
    sudo chmod -R 777 $APP_PATH/public/build
fi

# Node modules
if [ -d "$APP_PATH/node_modules" ]; then
    echo "=æ Configuration node_modules..."
    sudo chmod -R 755 $APP_PATH/node_modules
    [ -d "$APP_PATH/node_modules/.bin" ] && sudo chmod -R +x $APP_PATH/node_modules/.bin/*
fi

# Scripts exécutables
echo "=€ Scripts exécutables..."
[ -f "$APP_PATH/artisan" ] && chmod +x $APP_PATH/artisan
[ -f "$APP_PATH/install.sh" ] && chmod +x $APP_PATH/install.sh
[ -f "$APP_PATH/fix-permissions.sh" ] && chmod +x $APP_PATH/fix-permissions.sh
[ -f "$APP_PATH/fix-permissions-dev.sh" ] && chmod +x $APP_PATH/fix-permissions-dev.sh

echo " Permissions DEV appliquées!"
echo ""
echo "  ATTENTION: Ces permissions sont UNIQUEMENT pour le développement!"
echo "   Ne JAMAIS utiliser en production (permissions 777 = risque de sécurité)"
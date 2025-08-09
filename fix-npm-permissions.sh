#!/bin/bash

# ============================================
# Script de réparation rapide des permissions NPM/Vite
# Pour corriger les erreurs EACCES lors du build
# ============================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Utilise le répertoire courant ou le chemin spécifié
if [ -n "$1" ]; then
    APP_PATH="$1"
else
    APP_PATH="$(pwd)"
fi

echo -e "${GREEN}🔧 Réparation rapide des permissions NPM/Vite${NC}"
echo -e "${YELLOW}Répertoire:${NC} $APP_PATH"
echo ""

# Détection de l'utilisateur
if [ -d "/home/ploi" ] || id "ploi" &>/dev/null; then
    WEB_USER="ploi"
    WEB_GROUP="ploi"
    echo -e "${GREEN}Environnement Ploi détecté${NC}"
else
    WEB_USER="www-data"
    WEB_GROUP="www-data"
fi

# Vérifier si on est root/sudo
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Ce script doit être exécuté avec sudo${NC}"
    echo "Usage: sudo ./fix-npm-permissions.sh [chemin]"
    exit 1
fi

# 1. Corriger les permissions node_modules/.bin
if [ -d "$APP_PATH/node_modules/.bin" ]; then
    echo "✓ Correction de node_modules/.bin..."
    chmod -R 755 "$APP_PATH/node_modules/.bin"
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/node_modules/.bin"
fi

# 2. Corriger esbuild
if [ -d "$APP_PATH/node_modules/@esbuild" ]; then
    echo "✓ Correction d'esbuild..."
    # Rendre tous les binaires esbuild exécutables
    find "$APP_PATH/node_modules/@esbuild" -type f \( -name "esbuild" -o -name "*.node" \) -exec chmod 755 {} \;
    
    # Permissions pour tous les dossiers bin
    find "$APP_PATH/node_modules/@esbuild" -type d -name "bin" -exec chmod -R 755 {} \;
    
    # Propriétaire correct
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/node_modules/@esbuild"
fi

# 3. Corriger rollup
if [ -d "$APP_PATH/node_modules/@rollup" ]; then
    echo "✓ Correction de rollup..."
    find "$APP_PATH/node_modules/@rollup" -type f -name "*.node" -exec chmod 755 {} \;
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/node_modules/@rollup"
fi

# 4. Corriger vite
if [ -f "$APP_PATH/node_modules/vite/bin/vite.js" ]; then
    echo "✓ Correction de vite..."
    chmod 755 "$APP_PATH/node_modules/vite/bin/vite.js"
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/node_modules/vite"
fi

# 5. Corriger le cache npm
if [ -d "$APP_PATH/.npm" ]; then
    echo "✓ Correction du cache npm..."
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/.npm"
    chmod -R 755 "$APP_PATH/.npm"
fi

# 6. Corriger node_modules entier si nécessaire
echo "✓ Vérification globale de node_modules..."
if [ -d "$APP_PATH/node_modules" ]; then
    # Propriétaire
    chown -R $WEB_USER:$WEB_GROUP "$APP_PATH/node_modules"
    
    # Permissions de base
    find "$APP_PATH/node_modules" -type d -exec chmod 755 {} \;
    find "$APP_PATH/node_modules" -type f -exec chmod 644 {} \;
    
    # Exécutables
    find "$APP_PATH/node_modules" -type f -perm /u+x -exec chmod 755 {} \;
fi

# 7. Nettoyer et reconstruire si demandé
if [ "$2" == "--rebuild" ]; then
    echo ""
    echo -e "${YELLOW}Reconstruction des dépendances...${NC}"
    cd "$APP_PATH"
    
    # Supprimer node_modules et package-lock
    rm -rf node_modules package-lock.json
    
    # Réinstaller
    sudo -u $WEB_USER npm install
    
    echo "✓ Dépendances réinstallées"
fi

echo ""
echo -e "${GREEN}✅ Permissions NPM/Vite corrigées!${NC}"
echo ""
echo "Vous pouvez maintenant exécuter:"
echo "  npm run build"
echo ""
echo "Options disponibles:"
echo "  --rebuild : Supprime node_modules et réinstalle tout"
echo ""
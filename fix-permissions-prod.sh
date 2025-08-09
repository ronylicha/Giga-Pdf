#!/bin/bash

# ============================================
# Script de correction des permissions pour Laravel en production
# Application: Giga-PDF
# ============================================

# Couleurs pour l'output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
APP_PATH="/var/www/html/giga-pdf"
WEB_USER="www-data"
WEB_GROUP="www-data"
CURRENT_USER=$(whoami)

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Fix Permissions Script - Giga-PDF${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Vérifier que le script est exécuté avec sudo
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Ce script doit être exécuté avec sudo${NC}"
    echo "Usage: sudo ./fix-permissions-prod.sh"
    exit 1
fi

# Vérifier que le répertoire existe
if [ ! -d "$APP_PATH" ]; then
    echo -e "${RED}Erreur: Le répertoire $APP_PATH n'existe pas${NC}"
    exit 1
fi

echo -e "${YELLOW}Répertoire de l'application:${NC} $APP_PATH"
echo -e "${YELLOW}Utilisateur web:${NC} $WEB_USER:$WEB_GROUP"
echo ""

# Fonction pour afficher la progression
show_progress() {
    echo -e "${GREEN}✓${NC} $1"
}

# 1. Définir le propriétaire pour tous les fichiers
echo -e "${YELLOW}Étape 1: Configuration du propriétaire des fichiers...${NC}"
chown -R $WEB_USER:$WEB_GROUP $APP_PATH
show_progress "Propriétaire défini sur $WEB_USER:$WEB_GROUP"

# 2. Permissions de base pour tous les fichiers et dossiers
echo -e "${YELLOW}Étape 2: Configuration des permissions de base...${NC}"
find $APP_PATH -type f -exec chmod 644 {} \;
show_progress "Permissions des fichiers définies à 644"
find $APP_PATH -type d -exec chmod 755 {} \;
show_progress "Permissions des dossiers définies à 755"

# 3. Permissions spéciales pour les répertoires de stockage
echo -e "${YELLOW}Étape 3: Configuration des répertoires de stockage...${NC}"

# Storage directories
chmod -R 775 $APP_PATH/storage
show_progress "Storage: 775"

# Storage subdirectories qui nécessitent l'écriture
chmod -R 775 $APP_PATH/storage/app
chmod -R 775 $APP_PATH/storage/app/public
chmod -R 775 $APP_PATH/storage/app/documents
chmod -R 775 $APP_PATH/storage/app/conversions
chmod -R 775 $APP_PATH/storage/app/temp
chmod -R 775 $APP_PATH/storage/framework
chmod -R 775 $APP_PATH/storage/framework/cache
chmod -R 775 $APP_PATH/storage/framework/sessions
chmod -R 775 $APP_PATH/storage/framework/views
chmod -R 775 $APP_PATH/storage/logs
show_progress "Sous-répertoires storage configurés"

# Créer les répertoires s'ils n'existent pas
mkdir -p $APP_PATH/storage/app/documents
mkdir -p $APP_PATH/storage/app/conversions
mkdir -p $APP_PATH/storage/app/temp
mkdir -p $APP_PATH/storage/app/public
mkdir -p $APP_PATH/storage/framework/cache/data
mkdir -p $APP_PATH/storage/framework/sessions
mkdir -p $APP_PATH/storage/framework/views
mkdir -p $APP_PATH/storage/framework/testing
mkdir -p $APP_PATH/storage/logs
show_progress "Répertoires manquants créés"

# 4. Bootstrap cache
echo -e "${YELLOW}Étape 4: Configuration du cache Bootstrap...${NC}"
chmod -R 775 $APP_PATH/bootstrap/cache
show_progress "Bootstrap cache: 775"

# 5. Fichiers .env
echo -e "${YELLOW}Étape 5: Sécurisation du fichier .env...${NC}"
if [ -f "$APP_PATH/.env" ]; then
    chmod 640 $APP_PATH/.env
    chown $WEB_USER:$WEB_GROUP $APP_PATH/.env
    show_progress "Fichier .env sécurisé (640)"
else
    echo -e "${YELLOW}⚠ Fichier .env non trouvé${NC}"
fi

# 6. Artisan executable
echo -e "${YELLOW}Étape 6: Configuration d'artisan...${NC}"
chmod 755 $APP_PATH/artisan
show_progress "Artisan rendu exécutable"

# 7. Vendor binaries
echo -e "${YELLOW}Étape 7: Configuration des binaires vendor...${NC}"
if [ -d "$APP_PATH/vendor/bin" ]; then
    chmod -R 755 $APP_PATH/vendor/bin
    show_progress "Binaires vendor configurés"
fi

# 8. Public directory
echo -e "${YELLOW}Étape 8: Configuration du répertoire public...${NC}"
chmod 755 $APP_PATH/public
find $APP_PATH/public -type f -exec chmod 644 {} \;
find $APP_PATH/public -type d -exec chmod 755 {} \;
show_progress "Répertoire public configuré"

# 9. Storage link
echo -e "${YELLOW}Étape 9: Vérification du lien symbolique storage...${NC}"
if [ ! -L "$APP_PATH/public/storage" ]; then
    echo "Création du lien symbolique storage..."
    cd $APP_PATH
    sudo -u $WEB_USER php artisan storage:link
    show_progress "Lien symbolique storage créé"
else
    show_progress "Lien symbolique storage déjà existant"
fi

# 10. Scripts personnalisés
echo -e "${YELLOW}Étape 10: Configuration des scripts personnalisés...${NC}"
if [ -f "$APP_PATH/fix-permissions-prod.sh" ]; then
    chmod 755 $APP_PATH/fix-permissions-prod.sh
    show_progress "Script fix-permissions-prod.sh rendu exécutable"
fi

if [ -f "$APP_PATH/fix-permissions-dev.sh" ]; then
    chmod 755 $APP_PATH/fix-permissions-dev.sh
    show_progress "Script fix-permissions-dev.sh rendu exécutable"
fi

# 11. Logs spécifiques Laravel
echo -e "${YELLOW}Étape 11: Configuration des logs Laravel...${NC}"
if [ -f "$APP_PATH/storage/logs/laravel.log" ]; then
    chmod 664 $APP_PATH/storage/logs/laravel.log
    show_progress "Fichier laravel.log configuré"
fi

# Permettre la rotation des logs
chmod 775 $APP_PATH/storage/logs
show_progress "Répertoire logs configuré pour la rotation"

# 12. Configuration SELinux (si activé)
echo -e "${YELLOW}Étape 12: Vérification SELinux...${NC}"
if command -v getenforce &> /dev/null && [ "$(getenforce)" != "Disabled" ]; then
    echo "SELinux détecté, configuration des contextes..."
    semanage fcontext -a -t httpd_sys_rw_content_t "$APP_PATH/storage(/.*)?"
    semanage fcontext -a -t httpd_sys_rw_content_t "$APP_PATH/bootstrap/cache(/.*)?"
    restorecon -Rv $APP_PATH/storage
    restorecon -Rv $APP_PATH/bootstrap/cache
    show_progress "Contextes SELinux configurés"
else
    show_progress "SELinux non actif ou non installé"
fi

# 13. Optimisations Laravel pour la production
echo -e "${YELLOW}Étape 13: Optimisations Laravel...${NC}"
cd $APP_PATH

# Clear caches
sudo -u $WEB_USER php artisan cache:clear
show_progress "Cache cleared"

sudo -u $WEB_USER php artisan config:clear
show_progress "Config cache cleared"

sudo -u $WEB_USER php artisan view:clear
show_progress "View cache cleared"

# Rebuild caches pour production
sudo -u $WEB_USER php artisan config:cache
show_progress "Config cached"

sudo -u $WEB_USER php artisan route:cache
show_progress "Routes cached"

sudo -u $WEB_USER php artisan view:cache
show_progress "Views cached"

# 14. Permissions finales pour s'assurer que tout est correct
echo -e "${YELLOW}Étape 14: Vérification finale des permissions...${NC}"
chown -R $WEB_USER:$WEB_GROUP $APP_PATH/storage
chown -R $WEB_USER:$WEB_GROUP $APP_PATH/bootstrap/cache
show_progress "Permissions finales appliquées"

# Résumé
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}✓ Permissions corrigées avec succès!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Rappel des permissions appliquées:"
echo "  • Propriétaire: $WEB_USER:$WEB_GROUP"
echo "  • Fichiers: 644 (lecture pour tous, écriture pour propriétaire)"
echo "  • Dossiers: 755 (lecture/exécution pour tous, écriture pour propriétaire)"
echo "  • Storage & Cache: 775 (écriture pour propriétaire et groupe)"
echo "  • .env: 640 (lecture/écriture propriétaire, lecture groupe)"
echo ""
echo -e "${YELLOW}Note:${NC} Si vous rencontrez des problèmes, vérifiez:"
echo "  1. Que le serveur web utilise bien l'utilisateur $WEB_USER"
echo "  2. Que PHP-FPM utilise bien l'utilisateur $WEB_USER"
echo "  3. Les logs dans $APP_PATH/storage/logs/"
echo ""
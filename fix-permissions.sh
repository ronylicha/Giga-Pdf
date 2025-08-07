#!/bin/bash

# Script de correction des permissions pour Laravel
# Usage: sudo ./fix-permissions.sh [owner] [webserver_user]
# Exemple: sudo ./fix-permissions.sh ubuntu www-data

echo "=' Correction des permissions pour Giga-PDF..."

# Définir les variables
OWNER=${1:-$USER}  # Propriétaire (par défaut: utilisateur actuel)
WEB_USER=${2:-www-data}  # Utilisateur du serveur web (par défaut: www-data)
APP_PATH="/var/www/html/giga-pdf"

# Vérifier que le script est exécuté avec sudo
if [ "$EUID" -ne 0 ]; then 
    echo "L Ce script doit être exécuté avec sudo"
    exit 1
fi

# Vérifier que le répertoire existe
if [ ! -d "$APP_PATH" ]; then
    echo "L Le répertoire $APP_PATH n'existe pas"
    exit 1
fi

echo "=Á Répertoire: $APP_PATH"
echo "=d Propriétaire: $OWNER"
echo "< Utilisateur web: $WEB_USER"
echo ""

# Changer le propriétaire de tous les fichiers
echo "1ã Changement du propriétaire..."
chown -R $OWNER:$WEB_USER $APP_PATH

# Définir les permissions des répertoires
echo "2ã Configuration des permissions des répertoires..."
find $APP_PATH -type d -exec chmod 755 {} \;

# Définir les permissions des fichiers
echo "3ã Configuration des permissions des fichiers..."
find $APP_PATH -type f -exec chmod 644 {} \;

# Permissions spéciales pour les répertoires de stockage et cache
echo "4ã Configuration des répertoires de stockage et cache..."
chmod -R 775 $APP_PATH/storage
chmod -R 775 $APP_PATH/bootstrap/cache

# S'assurer que le serveur web peut écrire dans ces répertoires
chown -R $WEB_USER:$WEB_USER $APP_PATH/storage
chown -R $WEB_USER:$WEB_USER $APP_PATH/bootstrap/cache

# Permissions pour les répertoires publics
echo "5ã Configuration du répertoire public..."
chmod -R 775 $APP_PATH/public

# Créer les répertoires nécessaires s'ils n'existent pas
echo "6ã Création des répertoires manquants..."
directories=(
    "$APP_PATH/storage/app"
    "$APP_PATH/storage/app/public"
    "$APP_PATH/storage/app/private"
    "$APP_PATH/storage/framework"
    "$APP_PATH/storage/framework/cache"
    "$APP_PATH/storage/framework/cache/data"
    "$APP_PATH/storage/framework/sessions"
    "$APP_PATH/storage/framework/testing"
    "$APP_PATH/storage/framework/views"
    "$APP_PATH/storage/logs"
    "$APP_PATH/storage/documents"
    "$APP_PATH/storage/thumbnails"
    "$APP_PATH/storage/temp"
    "$APP_PATH/storage/exports"
    "$APP_PATH/bootstrap/cache"
    "$APP_PATH/public/build"
    "$APP_PATH/public/build/assets"
)

for dir in "${directories[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo "   Créé: $dir"
    fi
done

# Permissions spéciales pour ces répertoires
echo "7ã Application des permissions sur les répertoires créés..."
for dir in "${directories[@]}"; do
    if [[ $dir == *"storage"* ]] || [[ $dir == *"bootstrap/cache"* ]]; then
        chmod 775 "$dir"
        chown $WEB_USER:$WEB_USER "$dir"
    fi
done

# Rendre les scripts exécutables
echo "8ã Configuration des scripts exécutables..."
[ -f "$APP_PATH/artisan" ] && chmod +x $APP_PATH/artisan
[ -f "$APP_PATH/install.sh" ] && chmod +x $APP_PATH/install.sh
[ -f "$APP_PATH/fix-permissions.sh" ] && chmod +x $APP_PATH/fix-permissions.sh

# Permissions pour node_modules (si présent)
if [ -d "$APP_PATH/node_modules" ]; then
    echo "9ã Configuration de node_modules..."
    chown -R $OWNER:$OWNER $APP_PATH/node_modules
    chmod -R 755 $APP_PATH/node_modules
    
    # Rendre les binaires exécutables
    if [ -d "$APP_PATH/node_modules/.bin" ]; then
        chmod -R +x $APP_PATH/node_modules/.bin/*
    fi
fi

# Permissions pour vendor (si présent)
if [ -d "$APP_PATH/vendor" ]; then
    echo "= Configuration de vendor..."
    chown -R $OWNER:$OWNER $APP_PATH/vendor
    chmod -R 755 $APP_PATH/vendor
fi

# Fichier .env
if [ -f "$APP_PATH/.env" ]; then
    echo "1ã1ã Sécurisation du fichier .env..."
    chown $OWNER:$WEB_USER $APP_PATH/.env
    chmod 640 $APP_PATH/.env
fi

# Créer le lien symbolique pour le stockage public
echo "1ã2ã Création du lien symbolique pour le stockage..."
if [ ! -L "$APP_PATH/public/storage" ]; then
    cd $APP_PATH
    sudo -u $OWNER php artisan storage:link
    echo "   Lien symbolique créé"
fi

# Nettoyer les caches Laravel
echo "1ã3ã Nettoyage des caches..."
cd $APP_PATH
sudo -u $OWNER php artisan cache:clear
sudo -u $OWNER php artisan config:clear
sudo -u $OWNER php artisan route:clear
sudo -u $OWNER php artisan view:clear

# Optimiser pour la production (optionnel)
read -p "Voulez-vous optimiser pour la production? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "1ã4ã Optimisation pour la production..."
    sudo -u $OWNER php artisan config:cache
    sudo -u $OWNER php artisan route:cache
    sudo -u $OWNER php artisan view:cache
    sudo -u $OWNER php artisan icons:cache 2>/dev/null || true
fi

# Définir les ACL (Access Control Lists) si disponible
if command -v setfacl &> /dev/null; then
    echo "1ã5ã Configuration des ACL..."
    
    # Donner au serveur web les permissions d'écriture récursives
    setfacl -dR -m u:$WEB_USER:rwx $APP_PATH/storage
    setfacl -R -m u:$WEB_USER:rwx $APP_PATH/storage
    
    setfacl -dR -m u:$WEB_USER:rwx $APP_PATH/bootstrap/cache
    setfacl -R -m u:$WEB_USER:rwx $APP_PATH/bootstrap/cache
    
    echo "   ACL configurées"
else
    echo "9 ACL non disponible - utilisation des permissions standard"
fi

# Vérification finale
echo ""
echo " Permissions corrigées avec succès!"
echo ""
echo "=Ê Résumé:"
echo "  " Propriétaire: $OWNER:$WEB_USER"
echo "  " Répertoires: 755"
echo "  " Fichiers: 644"
echo "  " Storage & Cache: 775 (propriété $WEB_USER)"
echo "  " .env: 640 (lecture seule pour $WEB_USER)"
echo ""

# Afficher les permissions actuelles des répertoires importants
echo "=Á Vérification des permissions:"
ls -la $APP_PATH/storage/ | head -5
echo "..."
ls -la $APP_PATH/bootstrap/cache/ | head -3

echo ""
echo "=¡ Conseils:"
echo "  " Exécutez ce script après chaque déploiement"
echo "  " Si vous avez des problèmes, vérifiez que $WEB_USER est correct"
echo "  " Pour Nginx/Apache, utilisez généralement www-data"
echo "  " Pour FPM, vérifiez votre configuration PHP-FPM"
echo ""
echo "=€ L'application devrait maintenant fonctionner correctement!"
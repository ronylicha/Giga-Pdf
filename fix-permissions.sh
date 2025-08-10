#!/bin/bash

# Script de correction des permissions pour Laravel
# Usage: sudo ./fix-permissions.sh [owner] [webserver_user]
# Exemple: sudo ./fix-permissions.sh ubuntu www-data

echo "=' Correction des permissions pour Giga-PDF..."

# D�finir les variables
OWNER=${1:-$USER}  # Propri�taire (par d�faut: utilisateur actuel)
WEB_USER=${2:-www-data}  # Utilisateur du serveur web (par d�faut: www-data)
APP_PATH="/var/www/html/giga-pdf"

# V�rifier que le script est ex�cut� avec sudo
if [ "$EUID" -ne 0 ]; then 
    echo "L Ce script doit �tre ex�cut� avec sudo"
    exit 1
fi

# V�rifier que le r�pertoire existe
if [ ! -d "$APP_PATH" ]; then
    echo "L Le r�pertoire $APP_PATH n'existe pas"
    exit 1
fi

echo "=� R�pertoire: $APP_PATH"
echo "=d Propri�taire: $OWNER"
echo "< Utilisateur web: $WEB_USER"
echo ""

# Changer le propri�taire de tous les fichiers
echo "1� Changement du propri�taire..."
chown -R $OWNER:$WEB_USER $APP_PATH

# D�finir les permissions des r�pertoires
echo "2� Configuration des permissions des r�pertoires..."
find $APP_PATH -type d -exec chmod 755 {} \;

# D�finir les permissions des fichiers
echo "3� Configuration des permissions des fichiers..."
find $APP_PATH -type f -exec chmod 644 {} \;

# Permissions sp�ciales pour les r�pertoires de stockage et cache
echo "4� Configuration des r�pertoires de stockage et cache..."
chmod -R 775 $APP_PATH/storage
chmod -R 775 $APP_PATH/bootstrap/cache

# S'assurer que le serveur web peut �crire dans ces r�pertoires
chown -R $WEB_USER:$WEB_USER $APP_PATH/storage
chown -R $WEB_USER:$WEB_USER $APP_PATH/bootstrap/cache

# Permissions pour les r�pertoires publics
echo "5� Configuration du r�pertoire public..."
chmod -R 775 $APP_PATH/public

# Cr�er les r�pertoires n�cessaires s'ils n'existent pas
echo "6� Cr�ation des r�pertoires manquants..."
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
        echo "   Cr��: $dir"
    fi
done

# Permissions sp�ciales pour ces r�pertoires
echo "7� Application des permissions sur les r�pertoires cr��s..."
for dir in "${directories[@]}"; do
    if [[ $dir == *"storage"* ]] || [[ $dir == *"bootstrap/cache"* ]]; then
        chmod 775 "$dir"
        chown $WEB_USER:$WEB_USER "$dir"
    fi
done

# Rendre les scripts ex�cutables
echo "8� Configuration des scripts ex�cutables..."
[ -f "$APP_PATH/artisan" ] && chmod +x $APP_PATH/artisan
[ -f "$APP_PATH/install.sh" ] && chmod +x $APP_PATH/install.sh
[ -f "$APP_PATH/fix-permissions.sh" ] && chmod +x $APP_PATH/fix-permissions.sh

# Permissions pour node_modules (si pr�sent)
if [ -d "$APP_PATH/node_modules" ]; then
    echo "9� Configuration de node_modules..."
    chown -R $OWNER:$OWNER $APP_PATH/node_modules
    chmod -R 755 $APP_PATH/node_modules
    
    # Rendre les binaires ex�cutables
    if [ -d "$APP_PATH/node_modules/.bin" ]; then
        chmod -R +x $APP_PATH/node_modules/.bin/*
    fi
fi

# Permissions pour vendor (si pr�sent)
if [ -d "$APP_PATH/vendor" ]; then
    echo "= Configuration de vendor..."
    chown -R $OWNER:$OWNER $APP_PATH/vendor
    chmod -R 755 $APP_PATH/vendor
fi

# Fichier .env
if [ -f "$APP_PATH/.env" ]; then
    echo "1�1� S�curisation du fichier .env..."
    chown $OWNER:$WEB_USER $APP_PATH/.env
    chmod 640 $APP_PATH/.env
fi

# Cr�er le lien symbolique pour le stockage public
echo "1�2� Cr�ation du lien symbolique pour le stockage..."
if [ ! -L "$APP_PATH/public/storage" ]; then
    cd $APP_PATH
    sudo -u $OWNER php artisan storage:link
    echo "   Lien symbolique cr��"
fi

# Nettoyer les caches Laravel
echo "1�3� Nettoyage des caches..."
cd $APP_PATH
sudo -u $OWNER php artisan cache:clear
sudo -u $OWNER php artisan config:clear
sudo -u $OWNER php artisan route:clear
sudo -u $OWNER php artisan view:clear

# Optimiser pour la production (optionnel)
read -p "Voulez-vous optimiser pour la production? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "1�4� Optimisation pour la production..."
    sudo -u $OWNER php artisan config:cache
    sudo -u $OWNER php artisan route:cache
    sudo -u $OWNER php artisan view:cache
    sudo -u $OWNER php artisan icons:cache 2>/dev/null || true
fi

# D�finir les ACL (Access Control Lists) si disponible
if command -v setfacl &> /dev/null; then
    echo "1�5� Configuration des ACL..."
    
    # Donner au serveur web les permissions d'�criture r�cursives
    setfacl -dR -m u:$WEB_USER:rwx $APP_PATH/storage
    setfacl -R -m u:$WEB_USER:rwx $APP_PATH/storage
    
    setfacl -dR -m u:$WEB_USER:rwx $APP_PATH/bootstrap/cache
    setfacl -R -m u:$WEB_USER:rwx $APP_PATH/bootstrap/cache
    
    echo "   ACL configur�es"
else
    echo "9 ACL non disponible - utilisation des permissions standard"
fi

# V�rification finale
echo ""
echo " Permissions corrig�es avec succ�s!"
echo ""
echo "=� R�sum�:"
echo "  " Propri�taire: $OWNER:$WEB_USER"
echo "  " R�pertoires: 755"
echo "  " Fichiers: 644"
echo "  " Storage & Cache: 775 (propri�t� $WEB_USER)"
echo "  " .env: 640 (lecture seule pour $WEB_USER)"
echo ""

# Afficher les permissions actuelles des r�pertoires importants
echo "=� V�rification des permissions:"
ls -la $APP_PATH/storage/ | head -5
echo "..."
ls -la $APP_PATH/bootstrap/cache/ | head -3

echo ""
echo "=� Conseils:"
echo "  " Ex�cutez ce script apr�s chaque d�ploiement"
echo "  " Si vous avez des probl�mes, v�rifiez que $WEB_USER est correct"
echo "  " Pour Nginx/Apache, utilisez g�n�ralement www-data"
echo "  " Pour FPM, v�rifiez votre configuration PHP-FPM"
echo ""
echo "=� L'application devrait maintenant fonctionner correctement!"
#!/bin/bash

# Script de configuration pour LibreOffice dans Giga-PDF
# Ce script doit être exécuté après le déploiement

echo "Configuration de LibreOffice pour Giga-PDF..."

# Créer les répertoires nécessaires
echo "Création des répertoires LibreOffice et conversions..."
mkdir -p storage/app/libreoffice/{cache,config,temp}
mkdir -p storage/app/conversions

# Définir les permissions appropriées
echo "Configuration des permissions..."
chmod -R 775 storage/app/libreoffice
chmod -R 775 storage/app/conversions

# Si exécuté en tant que root, changer le propriétaire
if [ "$EUID" -eq 0 ]; then
    echo "Configuration du propriétaire www-data..."
    chown -R www-data:www-data storage/app/libreoffice
    chown -R www-data:www-data storage/app/conversions
else
    echo "Avertissement: Exécutez ce script en tant que root pour définir le propriétaire www-data"
fi

# Nettoyer les anciens fichiers temporaires (optionnel)
echo "Nettoyage des fichiers temporaires..."
find storage/app/libreoffice/temp -type f -mtime +1 -delete 2>/dev/null || true
find storage/app/libreoffice/temp -type d -empty -delete 2>/dev/null || true

echo "Configuration LibreOffice terminée!"
echo ""
echo "Note: Assurez-vous que LibreOffice est installé sur le système:"
echo "  sudo apt-get update && sudo apt-get install -y libreoffice"
echo ""
echo "Pour tester la conversion:"
echo "  php artisan conversion:test [document_id] [format]"
# Guide des Permissions pour Giga-PDF

## =€ Scripts Disponibles

### 1. Script de Production
```bash
sudo ./fix-permissions.sh [owner] [webserver_user]
```
- **Usage par défaut**: `sudo ./fix-permissions.sh`
- **Usage personnalisé**: `sudo ./fix-permissions.sh ubuntu www-data`

### 2. Script de Développement (RAPIDE)
```bash
./fix-permissions-dev.sh
```
  **ATTENTION**: Ne jamais utiliser en production!

## =Ë Permissions Recommandées

### Structure des Permissions

| Élément | Propriétaire | Permissions | Notes |
|---------|-------------|-------------|-------|
| **Application** | `user:www-data` | `755` | Répertoires et fichiers standards |
| **storage/** | `www-data:www-data` | `775` | Écriture pour le serveur web |
| **bootstrap/cache/** | `www-data:www-data` | `775` | Cache Laravel |
| **public/** | `user:www-data` | `755` | Accès public en lecture |
| **.env** | `user:www-data` | `640` | Sécurité du fichier de configuration |
| **artisan** | `user:www-data` | `755` | Script exécutable |

## =' Résolution des Problèmes

### Erreur: Permission denied (Laravel)
```bash
# Solution rapide
sudo ./fix-permissions.sh
```

### Erreur: Cannot write to storage
```bash
# Permissions storage
sudo chmod -R 775 storage bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### Erreur: npm/composer permission denied
```bash
# Permissions pour les packages
sudo chown -R $USER:$USER vendor node_modules
```

### Erreur: Vite build failed
```bash
# Permissions pour le build
sudo chown -R $USER:$USER public/build
sudo chmod -R 755 public/build
```

## <× Après Chaque Déploiement

1. **Cloner/Mettre à jour le code**
```bash
git pull origin main
```

2. **Installer les dépendances**
```bash
composer install --optimize-autoloader --no-dev
npm install && npm run build
```

3. **Appliquer les permissions**
```bash
sudo ./fix-permissions.sh
```

4. **Optimiser Laravel**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## = Sécurité

### Permissions à NE JAMAIS utiliser en production:
- L `chmod 777` - Trop permissif
- L `chmod -R 777 *` - Danger critique
- L Propriété root:root pour les fichiers web

### Bonnes pratiques:
-  Utiliser les ACL pour des permissions granulaires
-  Séparer l'utilisateur du développeur et du serveur web
-  Restreindre l'accès au fichier .env
-  Utiliser des permissions minimales nécessaires

## =' Configuration par Serveur Web

### Apache (mod_php)
```bash
sudo ./fix-permissions.sh $USER www-data
```

### Nginx + PHP-FPM
```bash
# Vérifier l'utilisateur PHP-FPM
ps aux | grep php-fpm
# Généralement www-data
sudo ./fix-permissions.sh $USER www-data
```

### Docker
```bash
# Dans le container
docker exec -it giga-pdf-app bash
./fix-permissions.sh www-data www-data
```

## =Ý Commandes Utiles

### Vérifier les permissions
```bash
# Voir les permissions d'un répertoire
ls -la storage/

# Voir le propriétaire récursivement
ls -laR storage/ | head -20

# Vérifier l'utilisateur du serveur web
ps aux | grep -E 'nginx|apache|php-fpm'
```

### Diagnostiquer les problèmes
```bash
# Tester l'écriture dans storage
sudo -u www-data touch storage/test.txt

# Vérifier les logs
tail -f storage/logs/laravel.log
```

## <˜ Support

Si vous rencontrez des problèmes de permissions après avoir utilisé les scripts:

1. Vérifiez l'utilisateur du serveur web: `ps aux | grep nginx`
2. Vérifiez les logs Laravel: `tail -f storage/logs/laravel.log`
3. Vérifiez les logs système: `sudo tail -f /var/log/nginx/error.log`
4. Testez manuellement: `sudo -u www-data php artisan cache:clear`

## =Ú Ressources

- [Laravel Documentation - Deployment](https://laravel.com/docs/deployment)
- [Linux File Permissions](https://www.linux.com/training-tutorials/understanding-linux-file-permissions/)
- [ACL (Access Control Lists)](https://wiki.archlinux.org/title/Access_Control_Lists)
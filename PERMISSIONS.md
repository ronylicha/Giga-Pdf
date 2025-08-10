# Guide des Permissions pour Giga-PDF

## =� Scripts Disponibles

### 1. Script de Production
```bash
sudo ./fix-permissions.sh [owner] [webserver_user]
```
- **Usage par d�faut**: `sudo ./fix-permissions.sh`
- **Usage personnalis�**: `sudo ./fix-permissions.sh ubuntu www-data`

### 2. Script de D�veloppement (RAPIDE)
```bash
./fix-permissions-dev.sh
```
� **ATTENTION**: Ne jamais utiliser en production!

## =� Permissions Recommand�es

### Structure des Permissions

| �l�ment | Propri�taire | Permissions | Notes |
|---------|-------------|-------------|-------|
| **Application** | `user:www-data` | `755` | R�pertoires et fichiers standards |
| **storage/** | `www-data:www-data` | `775` | �criture pour le serveur web |
| **bootstrap/cache/** | `www-data:www-data` | `775` | Cache Laravel |
| **public/** | `user:www-data` | `755` | Acc�s public en lecture |
| **.env** | `user:www-data` | `640` | S�curit� du fichier de configuration |
| **artisan** | `user:www-data` | `755` | Script ex�cutable |

## =' R�solution des Probl�mes

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

## <� Apr�s Chaque D�ploiement

1. **Cloner/Mettre � jour le code**
```bash
git pull origin main
```

2. **Installer les d�pendances**
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

## = S�curit�

### Permissions � NE JAMAIS utiliser en production:
- L `chmod 777` - Trop permissif
- L `chmod -R 777 *` - Danger critique
- L Propri�t� root:root pour les fichiers web

### Bonnes pratiques:
-  Utiliser les ACL pour des permissions granulaires
-  S�parer l'utilisateur du d�veloppeur et du serveur web
-  Restreindre l'acc�s au fichier .env
-  Utiliser des permissions minimales n�cessaires

## =' Configuration par Serveur Web

### Apache (mod_php)
```bash
sudo ./fix-permissions.sh $USER www-data
```

### Nginx + PHP-FPM
```bash
# V�rifier l'utilisateur PHP-FPM
ps aux | grep php-fpm
# G�n�ralement www-data
sudo ./fix-permissions.sh $USER www-data
```

### Docker
```bash
# Dans le container
docker exec -it giga-pdf-app bash
./fix-permissions.sh www-data www-data
```

## =� Commandes Utiles

### V�rifier les permissions
```bash
# Voir les permissions d'un r�pertoire
ls -la storage/

# Voir le propri�taire r�cursivement
ls -laR storage/ | head -20

# V�rifier l'utilisateur du serveur web
ps aux | grep -E 'nginx|apache|php-fpm'
```

### Diagnostiquer les probl�mes
```bash
# Tester l'�criture dans storage
sudo -u www-data touch storage/test.txt

# V�rifier les logs
tail -f storage/logs/laravel.log
```

## <� Support

Si vous rencontrez des probl�mes de permissions apr�s avoir utilis� les scripts:

1. V�rifiez l'utilisateur du serveur web: `ps aux | grep nginx`
2. V�rifiez les logs Laravel: `tail -f storage/logs/laravel.log`
3. V�rifiez les logs syst�me: `sudo tail -f /var/log/nginx/error.log`
4. Testez manuellement: `sudo -u www-data php artisan cache:clear`

## =� Ressources

- [Laravel Documentation - Deployment](https://laravel.com/docs/deployment)
- [Linux File Permissions](https://www.linux.com/training-tutorials/understanding-linux-file-permissions/)
- [ACL (Access Control Lists)](https://wiki.archlinux.org/title/Access_Control_Lists)
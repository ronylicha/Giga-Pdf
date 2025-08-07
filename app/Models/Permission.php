<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'description',
    ];

    /**
     * Get all permissions grouped by category
     */
    public static function getGroupedPermissions(): array
    {
        return self::all()->groupBy('category')->map(function ($permissions) {
            return $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                ];
            });
        })->toArray();
    }

    /**
     * Get all permission slugs for a category
     */
    public static function getPermissionsForCategory(string $category): array
    {
        return self::where('category', $category)->pluck('slug')->toArray();
    }

    /**
     * Default permissions structure
     */
    public static function getDefaultPermissions(): array
    {
        return [
            'users' => [
                ['slug' => 'users.view', 'name' => 'Voir les utilisateurs', 'description' => 'Afficher la liste des utilisateurs'],
                ['slug' => 'users.create', 'name' => 'Créer des utilisateurs', 'description' => 'Créer de nouveaux utilisateurs'],
                ['slug' => 'users.update', 'name' => 'Modifier les utilisateurs', 'description' => 'Modifier les informations des utilisateurs'],
                ['slug' => 'users.delete', 'name' => 'Supprimer les utilisateurs', 'description' => 'Supprimer des utilisateurs'],
                ['slug' => 'users.roles', 'name' => 'Gérer les rôles des utilisateurs', 'description' => 'Attribuer ou retirer des rôles aux utilisateurs'],
            ],
            
            'documents' => [
                ['slug' => 'documents.view', 'name' => 'Voir les documents', 'description' => 'Afficher les documents'],
                ['slug' => 'documents.create', 'name' => 'Créer des documents', 'description' => 'Télécharger de nouveaux documents'],
                ['slug' => 'documents.update', 'name' => 'Modifier les documents', 'description' => 'Éditer et annoter les documents'],
                ['slug' => 'documents.delete', 'name' => 'Supprimer les documents', 'description' => 'Supprimer des documents'],
                ['slug' => 'documents.share', 'name' => 'Partager les documents', 'description' => 'Partager des documents avec d\'autres'],
                ['slug' => 'documents.download', 'name' => 'Télécharger les documents', 'description' => 'Télécharger des documents'],
                ['slug' => 'documents.convert', 'name' => 'Convertir les documents', 'description' => 'Convertir des documents vers d\'autres formats'],
            ],
            
            'tools' => [
                ['slug' => 'tools.merge', 'name' => 'Fusionner des PDF', 'description' => 'Utiliser l\'outil de fusion de PDF'],
                ['slug' => 'tools.split', 'name' => 'Diviser des PDF', 'description' => 'Utiliser l\'outil de division de PDF'],
                ['slug' => 'tools.rotate', 'name' => 'Rotation de PDF', 'description' => 'Utiliser l\'outil de rotation de PDF'],
                ['slug' => 'tools.compress', 'name' => 'Compresser des PDF', 'description' => 'Utiliser l\'outil de compression de PDF'],
                ['slug' => 'tools.watermark', 'name' => 'Ajouter un filigrane', 'description' => 'Utiliser l\'outil de filigrane'],
                ['slug' => 'tools.encrypt', 'name' => 'Chiffrer des PDF', 'description' => 'Utiliser l\'outil de chiffrement'],
                ['slug' => 'tools.ocr', 'name' => 'OCR', 'description' => 'Utiliser l\'outil OCR'],
                ['slug' => 'tools.extract', 'name' => 'Extraire des pages', 'description' => 'Utiliser l\'outil d\'extraction de pages'],
            ],
            
            'settings' => [
                ['slug' => 'settings.view', 'name' => 'Voir les paramètres', 'description' => 'Afficher les paramètres du tenant'],
                ['slug' => 'settings.update', 'name' => 'Modifier les paramètres', 'description' => 'Modifier les paramètres du tenant'],
                ['slug' => 'settings.billing', 'name' => 'Gérer la facturation', 'description' => 'Accéder aux informations de facturation'],
            ],
            
            'activity' => [
                ['slug' => 'activity.view', 'name' => 'Voir le journal d\'activité', 'description' => 'Consulter le journal d\'activité'],
                ['slug' => 'activity.export', 'name' => 'Exporter le journal', 'description' => 'Exporter le journal d\'activité'],
            ],
            
            'storage' => [
                ['slug' => 'storage.view', 'name' => 'Voir l\'utilisation du stockage', 'description' => 'Consulter l\'utilisation du stockage'],
                ['slug' => 'storage.manage', 'name' => 'Gérer le stockage', 'description' => 'Gérer et nettoyer le stockage'],
            ],
            
            'invitations' => [
                ['slug' => 'invitations.view', 'name' => 'Voir les invitations', 'description' => 'Afficher les invitations envoyées'],
                ['slug' => 'invitations.create', 'name' => 'Créer des invitations', 'description' => 'Inviter de nouveaux utilisateurs'],
                ['slug' => 'invitations.delete', 'name' => 'Annuler des invitations', 'description' => 'Annuler des invitations en attente'],
            ],
            
            'roles' => [
                ['slug' => 'roles.view', 'name' => 'Voir les rôles', 'description' => 'Afficher la liste des rôles'],
                ['slug' => 'roles.create', 'name' => 'Créer des rôles', 'description' => 'Créer de nouveaux rôles'],
                ['slug' => 'roles.update', 'name' => 'Modifier les rôles', 'description' => 'Modifier les rôles et permissions'],
                ['slug' => 'roles.delete', 'name' => 'Supprimer des rôles', 'description' => 'Supprimer des rôles personnalisés'],
            ],
            
            'admin' => [
                ['slug' => 'admin.tenants', 'name' => 'Gérer les tenants', 'description' => 'Gérer tous les tenants (Super Admin)'],
                ['slug' => 'admin.users', 'name' => 'Gérer tous les utilisateurs', 'description' => 'Gérer tous les utilisateurs (Super Admin)'],
                ['slug' => 'admin.system', 'name' => 'Paramètres système', 'description' => 'Accéder aux paramètres système (Super Admin)'],
                ['slug' => 'admin.monitoring', 'name' => 'Monitoring', 'description' => 'Accéder au monitoring système (Super Admin)'],
            ],
        ];
    }
}
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Documents
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.delete',
            'documents.share',
            'documents.convert',
            'documents.download',
            
            // Users
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.invite',
            
            // Settings
            'settings.view',
            'settings.edit',
            
            // Tenant
            'tenant.manage',
            'tenant.billing',
            
            // Admin
            'admin.access',
            'admin.users',
            'admin.settings',
            
            // Super Admin
            'super-admin.access',
            'super-admin.tenants',
            'super-admin.users',
            'super-admin.impersonate',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Super Admin - accès total système
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // Tenant Admin - gestion complète du tenant (sans team_id car role global)
        $tenantAdmin = Role::firstOrCreate(['name' => 'tenant-admin']);
        $tenantAdmin->givePermissionTo([
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.delete',
            'documents.share',
            'documents.convert',
            'documents.download',
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.invite',
            'settings.view',
            'settings.edit',
            'tenant.manage',
            'tenant.billing',
            'admin.access',
            'admin.users',
            'admin.settings',
        ]);

        // Manager - gestion des documents et utilisateurs
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $manager->givePermissionTo([
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.delete',
            'documents.share',
            'documents.convert',
            'documents.download',
            'users.view',
            'users.invite',
            'settings.view',
        ]);

        // Editor - édition et conversion de documents
        $editor = Role::firstOrCreate(['name' => 'editor']);
        $editor->givePermissionTo([
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.convert',
            'documents.download',
            'documents.share',
        ]);

        // Viewer - lecture seule
        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->givePermissionTo([
            'documents.view',
            'documents.download',
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}
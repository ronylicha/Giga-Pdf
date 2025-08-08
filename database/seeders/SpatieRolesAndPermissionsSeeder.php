<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SpatieRolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        // Don't set team ID for super-admin (global role)
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);

        // Create permissions
        $permissions = [
            // Document management
            'view documents',
            'create documents',
            'edit documents',
            'delete documents',
            'share documents',
            'convert documents',
            
            // User management
            'view users',
            'create users',
            'edit users',
            'delete users',
            
            // Role management
            'view roles',
            'create roles',
            'edit roles',
            'delete roles',
            'assign roles',
            
            // Tenant management
            'view tenant',
            'edit tenant',
            'manage tenant settings',
            'view tenant statistics',
            
            // System administration
            'access admin panel',
            'manage system settings',
            'view system logs',
            'manage all tenants',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Super Admin - has all permissions (global role without tenant)
        $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());
        
        // Note: Tenant-specific roles are created when a tenant is created
        // via the TenantPermissionService
    }
}
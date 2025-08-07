<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Create all permissions
            $this->createPermissions();
            
            // Create system roles
            $this->createSystemRoles();
        });
    }
    
    /**
     * Create all permissions in the system
     */
    private function createPermissions(): void
    {
        $permissions = Permission::getDefaultPermissions();
        
        foreach ($permissions as $category => $categoryPermissions) {
            foreach ($categoryPermissions as $permission) {
                Permission::firstOrCreate(
                    ['slug' => $permission['slug']],
                    [
                        'name' => $permission['name'],
                        'category' => $category,
                        'description' => $permission['description'],
                    ]
                );
            }
        }
    }
    
    /**
     * Create system roles with default permissions
     */
    private function createSystemRoles(): void
    {
        // Super Admin - Global role without tenant
        $superAdmin = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            [
                'name' => 'Super Administrateur',
                'description' => 'Administrateur système avec accès complet',
                'permissions' => Role::getDefaultPermissions(Role::SUPER_ADMIN),
                'is_system' => true,
                'level' => 0,
                'tenant_id' => null,
            ]
        );
        
        // Tenant Admin
        $tenantAdmin = Role::firstOrCreate(
            ['slug' => Role::TENANT_ADMIN],
            [
                'name' => 'Administrateur Tenant',
                'description' => 'Administrateur du tenant avec accès complet au tenant',
                'permissions' => Role::getDefaultPermissions(Role::TENANT_ADMIN),
                'is_system' => true,
                'level' => 10,
                'tenant_id' => null, // Will be cloned for each tenant
            ]
        );
        
        // Manager
        $manager = Role::firstOrCreate(
            ['slug' => Role::MANAGER],
            [
                'name' => 'Manager',
                'description' => 'Gestionnaire avec accès étendu',
                'permissions' => Role::getDefaultPermissions(Role::MANAGER),
                'is_system' => true,
                'level' => 20,
                'tenant_id' => null,
            ]
        );
        
        // Editor
        $editor = Role::firstOrCreate(
            ['slug' => Role::EDITOR],
            [
                'name' => 'Éditeur',
                'description' => 'Peut créer et éditer des documents',
                'permissions' => Role::getDefaultPermissions(Role::EDITOR),
                'is_system' => true,
                'level' => 30,
                'tenant_id' => null,
            ]
        );
        
        // Viewer
        $viewer = Role::firstOrCreate(
            ['slug' => Role::VIEWER],
            [
                'name' => 'Lecteur',
                'description' => 'Accès en lecture seule',
                'permissions' => Role::getDefaultPermissions(Role::VIEWER),
                'is_system' => true,
                'level' => 40,
                'tenant_id' => null,
            ]
        );
    }
}
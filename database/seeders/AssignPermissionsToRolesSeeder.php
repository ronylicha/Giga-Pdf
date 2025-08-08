<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Tenant;

class AssignPermissionsToRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        // First, ensure permissions exist
        $this->ensurePermissionsExist();
        
        // Assign permissions to super-admin (global role)
        $this->assignSuperAdminPermissions();
        
        // Assign permissions to tenant roles
        $this->assignTenantRolePermissions();
        
        echo "\n✅ Permissions have been assigned to roles successfully!\n";
    }
    
    private function ensurePermissionsExist(): void
    {
        echo "Ensuring permissions exist...\n";
        
        // Set no team context for global permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        
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
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        
        echo "✓ All permissions verified\n";
    }
    
    private function assignSuperAdminPermissions(): void
    {
        echo "\nAssigning permissions to super-admin role...\n";
        
        // Set no team context for super-admin
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        
        $superAdmin = Role::where('name', 'super-admin')
                          ->whereNull('team_id')
                          ->first();
        
        if ($superAdmin) {
            // Super admin gets all permissions
            $superAdmin->syncPermissions(Permission::all());
            echo "✓ Super-admin role has all permissions\n";
        } else {
            // Create super-admin role if it doesn't exist
            $superAdmin = Role::create([
                'name' => 'super-admin',
                'guard_name' => 'web',
                'team_id' => null
            ]);
            $superAdmin->syncPermissions(Permission::all());
            echo "✓ Created super-admin role with all permissions\n";
        }
    }
    
    private function assignTenantRolePermissions(): void
    {
        echo "\nAssigning permissions to tenant roles...\n";
        
        $tenants = Tenant::all();
        
        foreach ($tenants as $tenant) {
            echo "Processing tenant: {$tenant->name} (ID: {$tenant->id})\n";
            
            // Set team context for this tenant
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($tenant->id);
            
            // Define permissions for each role
            $rolePermissions = [
                'tenant-admin' => [
                    'view documents',
                    'create documents',
                    'edit documents',
                    'delete documents',
                    'share documents',
                    'convert documents',
                    'view users',
                    'create users',
                    'edit users',
                    'delete users',
                    'view roles',
                    'create roles',
                    'edit roles',
                    'delete roles',
                    'assign roles',
                    'view tenant',
                    'edit tenant',
                    'manage tenant settings',
                    'view tenant statistics',
                    'access admin panel',
                ],
                'manager' => [
                    'view documents',
                    'create documents',
                    'edit documents',
                    'delete documents',
                    'share documents',
                    'convert documents',
                    'view users',
                    'create users',
                    'edit users',
                    'view roles',
                    'assign roles',
                    'view tenant',
                    'view tenant statistics',
                    'access admin panel',
                ],
                'editor' => [
                    'view documents',
                    'create documents',
                    'edit documents',
                    'delete documents',
                    'share documents',
                    'convert documents',
                    'view users',
                    'view tenant',
                ],
                'viewer' => [
                    'view documents',
                    'view users',
                    'view tenant',
                ],
            ];
            
            foreach ($rolePermissions as $roleName => $permissions) {
                $role = Role::where('name', $roleName)
                           ->where('team_id', $tenant->id)
                           ->first();
                
                if ($role) {
                    // Use existing global permissions (no need to create team-specific permissions)
                    // Spatie Permission will handle the team context
                    $permissionModels = [];
                    foreach ($permissions as $permissionName) {
                        $permission = Permission::where('name', $permissionName)
                                               ->where('guard_name', 'web')
                                               ->first();
                        if ($permission) {
                            $permissionModels[] = $permission;
                        }
                    }
                    
                    $role->syncPermissions($permissionModels);
                    echo "  ✓ Assigned permissions to {$roleName} role\n";
                }
            }
        }
        
        // Reset team context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
    }
}

// Helper function to set team context
if (!function_exists('setPermissionsTeamId')) {
    function setPermissionsTeamId($teamId)
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($teamId);
    }
}
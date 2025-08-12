<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantPermissionService
{
    /**
     * Create default roles and permissions for a tenant
     */
    public function createTenantRolesAndPermissions(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            // Set the tenant context for Spatie Permission
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($tenant->id);

            // Create permissions for this tenant
            $permissions = $this->getDefaultPermissions();

            foreach ($permissions as $permission) {
                Permission::firstOrCreate(
                    ['name' => $permission, 'guard_name' => 'web'],
                    ['tenant_id' => $tenant->id]
                );
            }

            // Create roles for this tenant
            $this->createTenantRoles($tenant);

            // Reset the tenant context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        });
    }

    /**
     * Get default permissions list
     */
    private function getDefaultPermissions(): array
    {
        return [
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

            // Admin panel
            'access admin panel',
        ];
    }

    /**
     * Create roles for a tenant
     */
    private function createTenantRoles(Tenant $tenant): void
    {
        // Set tenant context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($tenant->id);

        // Tenant Admin - manages their tenant
        $tenantAdmin = Role::firstOrCreate(
            [
                'name' => 'tenant-admin',
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ],
            []
        );

        $tenantAdmin->givePermissionTo([
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
            'assign roles',
            'view tenant',
            'edit tenant',
            'manage tenant settings',
            'view tenant statistics',
            'access admin panel',
        ]);

        // Manager - manages documents and users
        $manager = Role::firstOrCreate(
            [
                'name' => 'manager',
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ],
            []
        );

        $manager->givePermissionTo([
            'view documents',
            'create documents',
            'edit documents',
            'delete documents',
            'share documents',
            'convert documents',
            'view users',
            'create users',
            'edit users',
        ]);

        // Editor - can work with documents
        $editor = Role::firstOrCreate(
            [
                'name' => 'editor',
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ],
            []
        );

        $editor->givePermissionTo([
            'view documents',
            'create documents',
            'edit documents',
            'share documents',
            'convert documents',
        ]);

        // Viewer - read-only access
        $viewer = Role::firstOrCreate(
            [
                'name' => 'viewer',
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ],
            []
        );

        $viewer->givePermissionTo([
            'view documents',
        ]);
    }

    /**
     * Assign role to user with tenant context
     */
    public function assignRoleToUser($user, string $roleName, int $tenantId): void
    {
        // Set tenant context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($tenantId);

        // Find the role for this specific tenant
        $role = Role::where('name', $roleName)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($role) {
            // Use syncRoles to ensure proper team assignment
            $user->syncRoles([$role]);
        }

        // Reset context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
    }
}

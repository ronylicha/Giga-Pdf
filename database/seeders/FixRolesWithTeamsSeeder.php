<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FixRolesWithTeamsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create global roles (for super-admin, no team_id)
        $this->createGlobalRoles();

        // Create tenant-specific roles
        $this->createTenantRoles();

        // Assign roles to users
        $this->assignRolesToUsers();

        echo "\n✅ Roles with teams have been configured successfully!\n";

        // Show summary
        $this->showSummary();
    }

    private function createGlobalRoles(): void
    {
        echo "Creating global roles (no team)...\n";

        // Super admin role - no team_id (global)
        setPermissionsTeamId(null);

        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web', 'team_id' => null]
        );

        echo "✓ Created/verified global super-admin role\n";
    }

    private function createTenantRoles(): void
    {
        echo "\nCreating tenant-specific roles...\n";

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            echo "Processing tenant: {$tenant->name} (ID: {$tenant->id})\n";

            // Set the team context for this tenant
            setPermissionsTeamId($tenant->id);

            $roles = [
                'tenant-admin',
                'manager',
                'editor',
                'viewer',
            ];

            foreach ($roles as $name) {
                Role::firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                    'team_id' => $tenant->id,
                ]);
            }

            echo "✓ Created/verified roles for tenant {$tenant->name}\n";
        }

        // Reset team context
        setPermissionsTeamId(null);
    }

    private function assignRolesToUsers(): void
    {
        echo "\nAssigning roles to users...\n";

        // Reset cached permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Assign super-admin role (no team context)
        setPermissionsTeamId(null);

        $superAdmin = User::where('email', 'superadmin@gigapdf.com')->first();
        if ($superAdmin) {
            // Remove all existing roles first
            $superAdmin->roles()->detach();
            $superAdmin->assignRole('super-admin');
            echo "✓ Assigned super-admin role to {$superAdmin->name}\n";
        }

        // Assign tenant-specific roles
        $tenantUsers = User::whereNotNull('tenant_id')->get();

        foreach ($tenantUsers as $user) {
            // Set the team context to the user's tenant
            setPermissionsTeamId($user->tenant_id);

            // Remove all existing roles for this team context
            $user->roles()->wherePivot('team_id', $user->tenant_id)->detach();

            // Determine role based on user characteristics
            $roleName = $this->determineUserRole($user);

            // Get the role for this specific team
            $role = Role::where('name', $roleName)
                       ->where('team_id', $user->tenant_id)
                       ->first();

            if ($role) {
                $user->assignRole($role);
                echo "✓ Assigned {$roleName} role to {$user->name} (Tenant ID: {$user->tenant_id})\n";
            } else {
                echo "✗ Role {$roleName} not found for tenant {$user->tenant_id}\n";
            }
        }

        // Reset team context
        setPermissionsTeamId(null);
    }

    private function determineUserRole(User $user): string
    {
        // Check email or name patterns to determine appropriate role
        $email = strtolower($user->email);
        $name = strtolower($user->name);

        if (str_contains($email, 'admin') || str_contains($name, 'admin')) {
            return 'tenant-admin';
        }

        if (str_contains($email, 'manager') || str_contains($name, 'manager')) {
            return 'manager';
        }

        if (str_contains($email, 'editor') || str_contains($name, 'editor')) {
            return 'editor';
        }

        // Default role for regular users
        return 'viewer';
    }

    private function showSummary(): void
    {
        echo "\n📊 Current User-Role Assignments:\n";
        echo "==================================\n";

        $users = User::with(['tenant'])->get();

        foreach ($users as $user) {
            $tenant = $user->tenant ? $user->tenant->name : 'Global';

            // Set team context to get correct roles
            if ($user->tenant_id) {
                setPermissionsTeamId($user->tenant_id);
                // Get roles for this specific team
                $roles = $user->roles()->where('roles.team_id', $user->tenant_id)->get();
            } else {
                setPermissionsTeamId(null);
                // Get global roles (team_id is null)
                $roles = $user->roles()->whereNull('roles.team_id')->get();
            }

            $roleNames = $roles->pluck('name')->implode(', ') ?: 'No roles';

            echo "{$user->name} ({$user->email})\n";
            echo "  Tenant: {$tenant}\n";
            echo "  Roles: {$roleNames}\n\n";
        }

        // Reset team context
        setPermissionsTeamId(null);
    }
}

// Helper function to set team context
if (! function_exists('setPermissionsTeamId')) {
    function setPermissionsTeamId($teamId)
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId($teamId);
    }
}

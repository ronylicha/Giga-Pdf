<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class FixUserRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure roles exist
        $this->ensureRolesExist();

        // Fix Super Admin
        $superAdmin = User::where('email', 'superadmin@gigapdf.com')->first();
        if ($superAdmin && ! $superAdmin->hasRole('super-admin')) {
            $superAdmin->assignRole('super-admin');
            echo "✓ Super admin role assigned to {$superAdmin->name}\n";
        }

        // Fix Tenant Admin
        $admin = User::where('email', 'admin@demo.com')->first();
        if ($admin && ! $admin->hasRole('tenant-admin')) {
            $admin->assignRole('tenant-admin');
            echo "✓ Tenant admin role assigned to {$admin->name}\n";
        }

        echo "\n✅ User roles have been fixed successfully!\n";

        // Show summary
        $this->showSummary();
    }

    private function ensureRolesExist(): void
    {
        $roles = [
            'super-admin' => 'Full system access',
            'tenant-admin' => 'Full tenant access',
            'manager' => 'Manage users and documents',
            'editor' => 'Edit and convert documents',
            'viewer' => 'View documents only',
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description]
            );
        }

        echo "✓ All roles are present\n\n";
    }

    private function showSummary(): void
    {
        echo "\n📊 Current User-Role Assignments:\n";
        echo "==================================\n";

        $users = User::with('roles')->get();

        foreach ($users as $user) {
            $roles = $user->roles->pluck('name')->implode(', ') ?: 'No roles';
            echo "{$user->name} ({$user->email}): {$roles}\n";
        }
    }
}

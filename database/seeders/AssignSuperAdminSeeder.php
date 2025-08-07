<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;

class AssignSuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first user or create a super admin user
        $user = User::first();
        
        if (!$user) {
            $user = User::create([
                'name' => 'Super Admin',
                'email' => 'admin@giga-pdf.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'tenant_id' => null, // Super admin doesn't belong to a specific tenant
            ]);
        }
        
        // Get the super admin role
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();
        
        if ($superAdminRole && !$user->hasRole(Role::SUPER_ADMIN)) {
            // Assign super admin role
            $user->assignRole($superAdminRole);
            
            $this->command->info("Super Admin role assigned to user: {$user->email}");
        } else if (!$superAdminRole) {
            $this->command->error("Super Admin role not found. Please run RolesAndPermissionsSeeder first.");
        } else {
            $this->command->info("User {$user->email} already has Super Admin role.");
        }
    }
}
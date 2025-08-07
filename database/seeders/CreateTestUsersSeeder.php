<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Tenant;

class CreateTestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have a tenant
        $tenant = Tenant::first();
        if (!$tenant) {
            $tenant = Tenant::create([
                'name' => 'Demo Tenant',
                'slug' => 'demo',
                'settings' => [],
                'max_storage_gb' => 10,
                'max_users' => 50,
                'max_file_size_mb' => 100,
                'features' => ['all'],
                'subscription_plan' => 'premium',
                'subscription_expires_at' => now()->addYear(),
                'is_active' => true,
            ]);
        }
        
        // Create tenant-specific roles if they don't exist
        try {
            $seeder = new TenantRolesSeeder();
            $seeder->createRolesForTenant($tenant);
        } catch (\Exception $e) {
            // Roles probably already exist
        }
        
        // Create Tenant Admin
        $tenantAdmin = User::firstOrCreate(
            ['email' => 'tenant.admin@demo.com'],
            [
                'name' => 'Tenant Admin',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'tenant_id' => $tenant->id,
            ]
        );
        
        $tenantAdminRole = Role::where('tenant_id', $tenant->id)
            ->where('slug', 'tenant_admin_' . $tenant->id)
            ->first();
            
        if ($tenantAdminRole && !$tenantAdmin->hasRole('tenant_admin_' . $tenant->id)) {
            $tenantAdmin->assignRole($tenantAdminRole);
            $this->command->info("Tenant Admin role assigned to: {$tenantAdmin->email}");
        }
        
        // Create Manager
        $manager = User::firstOrCreate(
            ['email' => 'manager@demo.com'],
            [
                'name' => 'Manager User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'tenant_id' => $tenant->id,
            ]
        );
        
        $managerRole = Role::where('tenant_id', $tenant->id)
            ->where('slug', 'manager_' . $tenant->id)
            ->first();
            
        if ($managerRole && !$manager->hasRole('manager_' . $tenant->id)) {
            $manager->assignRole($managerRole);
            $this->command->info("Manager role assigned to: {$manager->email}");
        }
        
        // Create Editor
        $editor = User::firstOrCreate(
            ['email' => 'editor@demo.com'],
            [
                'name' => 'Editor User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'tenant_id' => $tenant->id,
            ]
        );
        
        $editorRole = Role::where('tenant_id', $tenant->id)
            ->where('slug', 'editor_' . $tenant->id)
            ->first();
            
        if ($editorRole && !$editor->hasRole('editor_' . $tenant->id)) {
            $editor->assignRole($editorRole);
            $this->command->info("Editor role assigned to: {$editor->email}");
        }
        
        // Create Viewer
        $viewer = User::firstOrCreate(
            ['email' => 'viewer@demo.com'],
            [
                'name' => 'Viewer User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'tenant_id' => $tenant->id,
            ]
        );
        
        $viewerRole = Role::where('tenant_id', $tenant->id)
            ->where('slug', 'viewer_' . $tenant->id)
            ->first();
            
        if ($viewerRole && !$viewer->hasRole('viewer_' . $tenant->id)) {
            $viewer->assignRole($viewerRole);
            $this->command->info("Viewer role assigned to: {$viewer->email}");
        }
        
        $this->command->info("\nTest users created successfully!");
        $this->command->info("All passwords are: password");
        $this->command->info("\nAccounts:");
        $this->command->info("- Super Admin: admin@demo.com");
        $this->command->info("- Tenant Admin: tenant.admin@demo.com");
        $this->command->info("- Manager: manager@demo.com");
        $this->command->info("- Editor: editor@demo.com");
        $this->command->info("- Viewer: viewer@demo.com");
    }
}
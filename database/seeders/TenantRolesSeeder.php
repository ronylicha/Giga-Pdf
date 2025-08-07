<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Tenant;

class TenantRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * This seeder creates tenant-specific roles based on system roles
     */
    public function run(): void
    {
        $tenants = Tenant::all();
        
        foreach ($tenants as $tenant) {
            $this->createRolesForTenant($tenant);
        }
    }
    
    /**
     * Create roles for a specific tenant
     */
    public function createRolesForTenant(Tenant $tenant): void
    {
        // Get system roles to clone (except super_admin which is global)
        $systemRoles = Role::whereNull('tenant_id')
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->where('is_system', true)
            ->get();
        
        foreach ($systemRoles as $systemRole) {
            // Check if role already exists for this tenant
            $existingRole = Role::where('tenant_id', $tenant->id)
                ->where('slug', $systemRole->slug)
                ->first();
            
            if (!$existingRole) {
                // For tenant-specific roles, we need to make the slug unique by appending tenant id
                $slug = $systemRole->slug . '_' . $tenant->id;
                
                Role::create([
                    'tenant_id' => $tenant->id,
                    'name' => $systemRole->name,
                    'slug' => $slug,
                    'description' => $systemRole->description,
                    'permissions' => $systemRole->permissions,
                    'is_system' => true,
                    'level' => $systemRole->level,
                ]);
            }
        }
    }
}
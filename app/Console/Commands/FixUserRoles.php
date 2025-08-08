<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Spatie\Permission\Models\Role;

class FixUserRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:fix-roles {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix user roles based on their current status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
            $this->info('=========================================');
        }
        
        // Ensure roles exist
        $this->ensureRolesExist();
        
        // Fix Super Admin
        $superAdmins = User::where(function($q) {
                $q->whereNull('tenant_id')
                  ->where(function($q2) {
                      $q2->where('email', 'like', '%superadmin%')
                         ->orWhere('name', 'like', '%Super Admin%');
                  });
            })
            ->get();
            
        foreach ($superAdmins as $user) {
            if (!$user->hasRole('super-admin')) {
                $this->info("Assigning super-admin role to: {$user->name} ({$user->email})");
                if (!$dryRun) {
                    $user->assignRole('super-admin');
                }
            }
        }
        
        // Fix Tenant Admins
        $tenantAdmins = User::whereNotNull('tenant_id')
            ->where(function($q) {
                $q->where('email', 'like', '%admin%')
                  ->orWhere('name', 'like', '%Admin%');
            })
            ->get();
            
        foreach ($tenantAdmins as $user) {
            if ($user->roles->isEmpty()) {
                $this->info("Assigning tenant-admin role to: {$user->name} ({$user->email})");
                if (!$dryRun) {
                    $user->assignRole('tenant-admin');
                }
            }
        }
        
        // Fix regular users
        $regularUsers = User::whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->get();
            
        foreach ($regularUsers as $user) {
            $this->info("Assigning viewer role to: {$user->name} ({$user->email})");
            if (!$dryRun) {
                $user->assignRole('viewer');
            }
        }
        
        if ($dryRun) {
            $this->info("\nTo apply these changes, run the command without --dry-run");
        } else {
            $this->info("\n✅ Roles have been fixed successfully!");
        }
        
        // Show summary
        $this->showSummary();
        
        return 0;
    }
    
    private function ensureRolesExist()
    {
        $roles = ['super-admin', 'tenant-admin', 'manager', 'editor', 'viewer'];
        
        foreach ($roles as $roleName) {
            if (!Role::where('name', $roleName)->exists()) {
                $this->info("Creating role: {$roleName}");
                Role::create(['name' => $roleName, 'guard_name' => 'web']);
            }
        }
    }
    
    private function showSummary()
    {
        $this->info("\n📊 Current Role Distribution:");
        $this->info("============================");
        
        $roles = Role::withCount('users')->get();
        
        foreach ($roles as $role) {
            $this->info("{$role->name}: {$role->users_count} users");
        }
        
        $usersWithoutRoles = User::whereDoesntHave('roles')->count();
        $this->info("No role: {$usersWithoutRoles} users");
    }
}
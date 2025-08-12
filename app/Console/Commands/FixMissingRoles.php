<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class FixMissingRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:missing-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix missing roles for users who registered without proper role assignment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for users without roles...');

        $users = User::all();
        $fixed = 0;

        foreach ($users as $user) {
            if (! $user->tenant_id) {
                $this->warn("User {$user->email} has no tenant_id, skipping...");

                continue;
            }

            // Set team context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);

            // Check if user has any roles
            if ($user->roles()->count() === 0) {
                $this->info("User {$user->email} (Tenant: {$user->tenant_id}) has no roles. Fixing...");

                // Check if tenant has roles created
                $tenantRoles = Role::where('team_id', $user->tenant_id)->count();

                if ($tenantRoles === 0) {
                    $this->info("Creating roles for tenant {$user->tenant_id}...");
                    $tenant = Tenant::find($user->tenant_id);
                    if ($tenant) {
                        $permissionService = new TenantPermissionService();
                        $permissionService->createTenantRolesAndPermissions($tenant);
                    }
                }

                // Check if this is the first user of the tenant (likely the admin)
                $firstUser = User::where('tenant_id', $user->tenant_id)
                    ->orderBy('id', 'asc')
                    ->first();

                if ($firstUser && $firstUser->id === $user->id) {
                    // This is the first user, make them tenant-admin
                    $user->assignRole('tenant-admin');
                    $this->info("  ✓ Assigned tenant-admin role to {$user->email}");
                } else {
                    // Other users get editor role by default
                    $user->assignRole('editor');
                    $this->info("  ✓ Assigned editor role to {$user->email}");
                }

                $fixed++;
            } else {
                $this->info("User {$user->email} already has roles: " . implode(', ', $user->getRoleNames()->toArray()));
            }
        }

        if ($fixed > 0) {
            $this->info("\n✓ Fixed {$fixed} users with missing roles.");
        } else {
            $this->info("\n✓ All users have roles assigned.");
        }

        return 0;
    }
}

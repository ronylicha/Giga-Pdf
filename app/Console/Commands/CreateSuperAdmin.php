<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CreateSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:super-admin 
                            {email : The email for the super admin}
                            {--name= : The name for the super admin}
                            {--password= : The password for the super admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a super admin user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $name = $this->option('name') ?: $this->ask('What is the super admin name?');
        $password = $this->option('password') ?: $this->secret('What is the super admin password? (min 8 characters)');
        
        // Validate password
        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters long.');
            return 1;
        }
        
        // Check if user already exists
        if (User::where('email', $email)->exists()) {
            $this->error("User with email {$email} already exists.");
            
            // Ask if we should make existing user a super admin
            if ($this->confirm('Do you want to make this user a super admin?')) {
                $user = User::where('email', $email)->first();
                
                // Don't set team context for super-admin
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
                
                // Assign super-admin role
                $user->assignRole('super-admin');
                
                $this->info("✓ User {$email} is now a super admin!");
                return 0;
            }
            
            return 1;
        }
        
        try {
            // Create user without tenant_id (super-admin is global)
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'tenant_id' => null, // Super admin doesn't belong to any tenant
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            
            // Don't set team context for super-admin
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            
            // Check if super-admin role exists, create if not
            $superAdminRole = Role::where('name', 'super-admin')
                ->whereNull('tenant_id')
                ->first();
                
            if (!$superAdminRole) {
                $this->info('Creating super-admin role...');
                $superAdminRole = Role::create([
                    'name' => 'super-admin',
                    'guard_name' => 'web'
                ]);
                
                // Give all permissions if they exist
                if (class_exists(\Spatie\Permission\Models\Permission::class)) {
                    $permissions = \Spatie\Permission\Models\Permission::all();
                    if ($permissions->count() > 0) {
                        $superAdminRole->givePermissionTo($permissions);
                    }
                }
            }
            
            // Assign super-admin role
            $user->assignRole('super-admin');
            
            $this->info("✓ Super admin created successfully!");
            $this->table(
                ['Property', 'Value'],
                [
                    ['Name', $user->name],
                    ['Email', $user->email],
                    ['Role', 'super-admin'],
                    ['Created At', $user->created_at],
                ]
            );
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Failed to create super admin: ' . $e->getMessage());
            return 1;
        }
    }
}
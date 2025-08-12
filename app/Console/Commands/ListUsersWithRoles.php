<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ListUsersWithRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:list-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all users with their roles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::with(['roles', 'tenant'])->get();

        if ($users->isEmpty()) {
            $this->error('No users found in the database.');

            return 1;
        }

        $this->info('Users and their roles:');
        $this->info('======================');

        $tableData = $users->map(function ($user) {
            $roles = $user->roles->pluck('name')->toArray();

            return [
                'ID' => $user->id,
                'Name' => $user->name,
                'Email' => $user->email,
                'Tenant' => $user->tenant ? $user->tenant->name : 'No tenant',
                'Roles' => empty($roles) ? 'No roles' : implode(', ', $roles),
                'Verified' => $user->email_verified_at ? '✓' : '✗',
            ];
        });

        $this->table(
            ['ID', 'Name', 'Email', 'Tenant', 'Roles', 'Verified'],
            $tableData
        );

        // Statistics
        $this->info("\nStatistics:");
        $this->info("Total users: " . $users->count());
        $this->info("Super admins: " . $users->filter(function ($u) {
            return $u->hasRole('super-admin');
        })->count());
        $this->info("Tenant admins: " . $users->filter(function ($u) {
            return $u->hasRole('tenant-admin');
        })->count());
        $this->info("Users with no roles: " . $users->filter(function ($u) {
            return $u->roles->isEmpty();
        })->count());

        return 0;
    }
}

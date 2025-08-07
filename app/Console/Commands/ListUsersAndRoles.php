<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class ListUsersAndRoles extends Command
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
    protected $description = 'List all users and their roles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::with('roles')->get();
        
        $headers = ['ID', 'Name', 'Email', 'Tenant ID', 'Roles', 'Is Super Admin', 'Is Tenant Admin'];
        $data = [];
        
        foreach ($users as $user) {
            $roles = $user->roles->pluck('slug')->implode(', ');
            
            $data[] = [
                $user->id,
                $user->name,
                $user->email,
                $user->tenant_id ?? 'N/A',
                $roles ?: 'No roles',
                $user->isSuperAdmin() ? 'Yes' : 'No',
                $user->isTenantAdmin() ? 'Yes' : 'No',
            ];
        }
        
        $this->table($headers, $data);
        
        return Command::SUCCESS;
    }
}
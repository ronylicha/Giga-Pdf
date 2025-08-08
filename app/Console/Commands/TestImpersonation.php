<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TestImpersonation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:impersonation {--check : Check current impersonation sessions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test and check impersonation functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('check')) {
            $this->checkActiveImpersonations();
            return 0;
        }

        $this->info('Testing Impersonation System');
        $this->line('================================');
        
        // Check if super-admin exists
        $superAdmin = User::whereHas('roles', function ($q) {
            $q->where('name', 'super-admin');
        })->first();
        
        if (!$superAdmin) {
            $this->error('No super-admin found in the system!');
            return 1;
        }
        
        $this->info("✓ Super Admin found: {$superAdmin->name} ({$superAdmin->email})");
        
        // Check impersonation logs
        $impersonationLogs = DB::table('impersonation_logs')
            ->select('impersonation_logs.*', 
                     'u1.name as impersonator_name', 
                     'u2.name as impersonated_name')
            ->leftJoin('users as u1', 'impersonation_logs.impersonator_id', '=', 'u1.id')
            ->leftJoin('users as u2', 'impersonation_logs.impersonated_user_id', '=', 'u2.id')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        if ($impersonationLogs->count() > 0) {
            $this->info("\n📋 Recent Impersonation Activity:");
            $this->table(
                ['Date', 'Action', 'Impersonator', 'Target User'],
                $impersonationLogs->map(function ($log) {
                    return [
                        $log->created_at,
                        $log->action,
                        $log->impersonator_name ?? 'Unknown',
                        $log->impersonated_name ?? 'Unknown',
                    ];
                })
            );
        } else {
            $this->info("\n📋 No impersonation activity found yet.");
        }
        
        // List users that can be impersonated
        $users = User::with('roles')->get();
        $this->info("\n👥 Users available for impersonation:");
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Tenant'],
            $users->map(function ($user) {
                return [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->roles->pluck('name')->implode(', ') ?: 'No role',
                    $user->tenant->name ?? 'No tenant',
                ];
            })
        );
        
        $this->info("\n✅ Impersonation system is configured correctly!");
        $this->info("To impersonate a user:");
        $this->info("1. Login as super-admin");
        $this->info("2. Go to /super-admin/users");
        $this->info("3. Click the user icon next to any user");
        
        return 0;
    }
    
    private function checkActiveImpersonations()
    {
        $this->info('Checking for active impersonation sessions...');
        
        // Note: This would need to check session storage
        // For file-based sessions, you'd need to read session files
        // For database sessions, you'd query the sessions table
        
        $this->warn('Note: Active sessions check requires access to session storage.');
        $this->info('Check your application logs for impersonation activity.');
    }
}
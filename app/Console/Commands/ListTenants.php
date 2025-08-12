<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ListTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:list 
                            {--active : Show only active tenants}
                            {--suspended : Show only suspended tenants}
                            {--format=table : Output format (table, json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all tenants with their statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $query = Tenant::withCount(['users', 'documents']);

        // Apply filters
        if ($this->option('active')) {
            $query->where('is_active', true)->where('is_suspended', false);
        } elseif ($this->option('suspended')) {
            $query->where('is_suspended', true);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return Command::SUCCESS;
        }

        // Calculate statistics for each tenant
        $data = $tenants->map(function ($tenant) {
            $storageUsed = $tenant->getStorageUsage();
            $storageLimit = $tenant->max_storage_gb * 1024 * 1024 * 1024;
            $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 2) : 0;

            return [
                'ID' => $tenant->id,
                'Name' => $tenant->name,
                'Slug' => $tenant->slug,
                'Domain' => $tenant->domain ?? 'N/A',
                'Users' => $tenant->users_count . '/' . $tenant->max_users,
                'Documents' => $tenant->documents_count,
                'Storage' => $this->formatBytes($storageUsed) . '/' . $tenant->max_storage_gb . 'GB (' . $storagePercent . '%)',
                'Plan' => $tenant->subscription_plan,
                'Status' => $this->getStatus($tenant),
                'Created' => $tenant->created_at->format('Y-m-d'),
            ];
        });

        // Output based on format
        switch ($this->option('format')) {
            case 'json':
                $this->line($data->toJson(JSON_PRETTY_PRINT));
                break;
                
            case 'csv':
                $this->outputCsv($data);
                break;
                
            default:
                $this->table(
                    ['ID', 'Name', 'Slug', 'Domain', 'Users', 'Documents', 'Storage', 'Plan', 'Status', 'Created'],
                    $data->toArray()
                );
                
                // Show summary
                $this->newLine();
                $this->info('Summary:');
                $this->line('Total Tenants: ' . $tenants->count());
                $this->line('Active: ' . $tenants->where('is_active', true)->where('is_suspended', false)->count());
                $this->line('Suspended: ' . $tenants->where('is_suspended', true)->count());
                $this->line('Total Users: ' . $tenants->sum('users_count'));
                $this->line('Total Documents: ' . $tenants->sum('documents_count'));
                $this->line('Total Storage Used: ' . $this->formatBytes($tenants->sum(function ($t) {
                    return $t->getStorageUsage();
                })));
        }

        return Command::SUCCESS;
    }

    /**
     * Get tenant status
     */
    private function getStatus(Tenant $tenant): string
    {
        if ($tenant->is_suspended) {
            return '<fg=red>Suspended</>';
        }
        
        if (!$tenant->is_active) {
            return '<fg=yellow>Inactive</>';
        }
        
        if ($tenant->subscription_expires_at && $tenant->subscription_expires_at->isPast()) {
            return '<fg=yellow>Expired</>';
        }
        
        if ($tenant->isStorageQuotaExceeded()) {
            return '<fg=yellow>Over Quota</>';
        }
        
        return '<fg=green>Active</>';
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . $units[$pow];
    }

    /**
     * Output data as CSV
     */
    private function outputCsv($data): void
    {
        // Headers
        $this->line('ID,Name,Slug,Domain,Users,Documents,Storage,Plan,Status,Created');
        
        // Data rows
        foreach ($data as $row) {
            $values = array_map(function ($value) {
                // Remove color tags from status
                $value = strip_tags($value);
                // Escape quotes and wrap in quotes if contains comma
                if (str_contains($value, ',') || str_contains($value, '"')) {
                    return '"' . str_replace('"', '""', $value) . '"';
                }
                return $value;
            }, $row);
            
            $this->line(implode(',', $values));
        }
    }
}
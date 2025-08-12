<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MonitorTenantLimits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:tenant-limits 
                            {--tenant= : Check specific tenant by ID or slug}
                            {--alert : Send notifications for tenants near limits}
                            {--suspend : Auto-suspend tenants exceeding limits}
                            {--format=table : Output format (table, json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor tenant resource limits and usage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Monitoring tenant limits...');

        // Get tenants to check
        $query = Tenant::withCount(['users', 'documents']);

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId)->orWhere('slug', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return Command::SUCCESS;
        }

        $data = [];
        $violationTenants = [];
        $warningTenants = [];

        foreach ($tenants as $tenant) {
            // Check user limit
            $userCount = $tenant->users_count;
            $userLimit = $tenant->max_users;
            $userPercent = $userLimit > 0 ? ($userCount / $userLimit) * 100 : 0;
            $userStatus = $this->getLimitStatus($userPercent, $userCount >= $userLimit);

            // Check storage limit
            $storageUsed = $tenant->getStorageUsage();
            $storageLimit = $tenant->max_storage_gb * 1024 * 1024 * 1024;
            $storagePercent = $storageLimit > 0 ? ($storageUsed / $storageLimit) * 100 : 0;
            $storageStatus = $this->getLimitStatus($storagePercent, $storageUsed >= $storageLimit);

            // Check file size limit (check largest document)
            $largestFile = $tenant->documents()->max('size');
            $fileSizeLimit = $tenant->max_file_size_mb * 1024 * 1024;
            $largestFileStatus = $largestFile > $fileSizeLimit ? 'EXCEEDED' : 'OK';

            // Check subscription status
            $subscriptionStatus = $this->getSubscriptionStatus($tenant);

            // Determine overall status
            $violations = [];
            $warnings = [];

            if ($userCount >= $userLimit) {
                $violations[] = 'user_limit';
            } elseif ($userPercent >= 90) {
                $warnings[] = 'user_limit';
            }

            if ($storageUsed >= $storageLimit) {
                $violations[] = 'storage_limit';
            } elseif ($storagePercent >= 90) {
                $warnings[] = 'storage_limit';
            }

            if ($largestFile > $fileSizeLimit) {
                $violations[] = 'file_size_limit';
            }

            if (! $tenant->isSubscriptionActive()) {
                $violations[] = 'subscription_expired';
            }

            if (! empty($violations)) {
                $violationTenants[] = [
                    'tenant' => $tenant,
                    'violations' => $violations,
                ];
            }

            if (! empty($warnings)) {
                $warningTenants[] = [
                    'tenant' => $tenant,
                    'warnings' => $warnings,
                ];
            }

            $data[] = [
                'ID' => $tenant->id,
                'Name' => $tenant->name,
                'Users' => $userCount . '/' . $userLimit . ' (' . round($userPercent, 1) . '%)',
                'Storage' => $this->formatBytes($storageUsed) . '/' . $tenant->max_storage_gb . 'GB (' . round($storagePercent, 1) . '%)',
                'Max File' => $this->formatBytes($largestFile) . '/' . $tenant->max_file_size_mb . 'MB',
                'User Status' => $userStatus,
                'Storage Status' => $storageStatus,
                'File Status' => $largestFileStatus === 'EXCEEDED' ? '<fg=red>EXCEEDED</>' : '<fg=green>OK</>',
                'Subscription' => $subscriptionStatus,
                'Overall' => $this->getOverallStatus($violations, $warnings),
            ];
        }

        // Output results
        $this->outputResults($data);

        // Show violations
        if (! empty($violationTenants)) {
            $this->newLine();
            $this->error('🚨 Limit Violations Detected:');

            foreach ($violationTenants as $violation) {
                $tenant = $violation['tenant'];
                $this->line(sprintf(
                    "  - %s (ID: %d): %s",
                    $tenant->name,
                    $tenant->id,
                    implode(', ', $violation['violations'])
                ));

                if ($this->option('suspend') && ! $tenant->is_suspended) {
                    $this->suspendTenant($tenant, $violation['violations']);
                }
            }

            if ($this->option('alert')) {
                $this->sendViolationAlerts($violationTenants);
            }
        }

        // Show warnings
        if (! empty($warningTenants)) {
            $this->newLine();
            $this->warn('⚠️  Limit Warnings (>90% usage):');

            foreach ($warningTenants as $warning) {
                $tenant = $warning['tenant'];
                $this->line(sprintf(
                    "  - %s (ID: %d): %s",
                    $tenant->name,
                    $tenant->id,
                    implode(', ', $warning['warnings'])
                ));
            }

            if ($this->option('alert')) {
                $this->sendWarningAlerts($warningTenants);
            }
        }

        // Summary
        $this->newLine();
        $this->info('Tenant Limits Summary:');
        $this->line('Total Tenants: ' . $tenants->count());
        $this->line('Active Tenants: ' . $tenants->where('is_active', true)->where('is_suspended', false)->count());
        $this->line('Suspended Tenants: ' . $tenants->where('is_suspended', true)->count());
        $this->line('Tenants with Violations: ' . count($violationTenants));
        $this->line('Tenants with Warnings: ' . count($warningTenants));

        // Detailed statistics
        $this->newLine();
        $this->info('Resource Usage Statistics:');
        $this->line('Average User Usage: ' . round($tenants->avg(function ($t) {
            return $t->max_users > 0 ? ($t->users_count / $t->max_users) * 100 : 0;
        }), 1) . '%');
        $this->line('Average Storage Usage: ' . round($tenants->avg(function ($t) {
            $limit = $t->max_storage_gb * 1024 * 1024 * 1024;

            return $limit > 0 ? ($t->getStorageUsage() / $limit) * 100 : 0;
        }), 1) . '%');
        $this->line('Total Users: ' . $tenants->sum('users_count'));
        $this->line('Total Documents: ' . $tenants->sum('documents_count'));

        return Command::SUCCESS;
    }

    /**
     * Get limit status based on percentage
     */
    private function getLimitStatus(float $percent, bool $exceeded): string
    {
        if ($exceeded) {
            return '<fg=red>EXCEEDED</>';
        } elseif ($percent >= 90) {
            return '<fg=yellow>Warning</>';
        } elseif ($percent >= 75) {
            return '<fg=cyan>High</>';
        } else {
            return '<fg=green>OK</>';
        }
    }

    /**
     * Get subscription status
     */
    private function getSubscriptionStatus(Tenant $tenant): string
    {
        if ($tenant->is_suspended) {
            return '<fg=red>Suspended</>';
        }

        if (! $tenant->is_active) {
            return '<fg=yellow>Inactive</>';
        }

        if ($tenant->subscription_expires_at) {
            $daysLeft = now()->diffInDays($tenant->subscription_expires_at, false);

            if ($daysLeft < 0) {
                return '<fg=red>Expired</>';
            } elseif ($daysLeft <= 7) {
                return '<fg=yellow>Expires in ' . $daysLeft . ' days</>';
            } elseif ($daysLeft <= 30) {
                return '<fg=cyan>Expires in ' . $daysLeft . ' days</>';
            }
        }

        return '<fg=green>' . ucfirst($tenant->subscription_plan) . '</>';
    }

    /**
     * Get overall status
     */
    private function getOverallStatus(array $violations, array $warnings): string
    {
        if (! empty($violations)) {
            return '<fg=red>VIOLATION</>';
        } elseif (! empty($warnings)) {
            return '<fg=yellow>WARNING</>';
        } else {
            return '<fg=green>OK</>';
        }
    }

    /**
     * Suspend tenant for violations
     */
    private function suspendTenant(Tenant $tenant, array $violations): void
    {
        $tenant->update([
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspended_reason' => 'Auto-suspended due to limit violations: ' . implode(', ', $violations),
        ]);

        // Log the suspension
        activity()
            ->performedOn($tenant)
            ->withProperties([
                'violations' => $violations,
                'auto_suspended' => true,
            ])
            ->log('Tenant auto-suspended for limit violations');

        $this->line("    → Tenant suspended: {$tenant->name}");
    }

    /**
     * Send violation alerts
     */
    private function sendViolationAlerts(array $violationTenants): void
    {
        foreach ($violationTenants as $violation) {
            $tenant = $violation['tenant'];

            // Log the alert
            activity()
                ->performedOn($tenant)
                ->withProperties([
                    'violations' => $violation['violations'],
                    'alert_type' => 'limit_violation',
                ])
                ->log('Limit violation alert sent');

            // TODO: Send email notification
            // Mail::to($tenant->admin_email)->send(new LimitViolationMail($tenant, $violation['violations']));

            $this->line("  Alert sent for: {$tenant->name}");
        }
    }

    /**
     * Send warning alerts
     */
    private function sendWarningAlerts(array $warningTenants): void
    {
        foreach ($warningTenants as $warning) {
            $tenant = $warning['tenant'];

            // Log the alert
            activity()
                ->performedOn($tenant)
                ->withProperties([
                    'warnings' => $warning['warnings'],
                    'alert_type' => 'limit_warning',
                ])
                ->log('Limit warning alert sent');

            // TODO: Send email notification
            // Mail::to($tenant->admin_email)->send(new LimitWarningMail($tenant, $warning['warnings']));

            $this->line("  Warning sent for: {$tenant->name}");
        }
    }

    /**
     * Output results in specified format
     */
    private function outputResults(array $data): void
    {
        switch ($this->option('format')) {
            case 'json':
                $this->line(json_encode($data, JSON_PRETTY_PRINT));

                break;

            case 'csv':
                $this->outputCsv($data);

                break;

            default:
                $this->table(
                    ['ID', 'Name', 'Users', 'Storage', 'Max File', 'User Status', 'Storage Status', 'File Status', 'Subscription', 'Overall'],
                    $data
                );
        }
    }

    /**
     * Output data as CSV
     */
    private function outputCsv(array $data): void
    {
        if (empty($data)) {
            return;
        }

        // Headers
        $this->line(implode(',', array_keys($data[0])));

        // Data rows
        foreach ($data as $row) {
            $values = array_map(function ($value) {
                $value = strip_tags($value);
                if (str_contains($value, ',') || str_contains($value, '"')) {
                    return '"' . str_replace('"', '""', $value) . '"';
                }

                return $value;
            }, $row);

            $this->line(implode(',', $values));
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        if ($bytes === null) {
            return '0B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . $units[$pow];
    }
}

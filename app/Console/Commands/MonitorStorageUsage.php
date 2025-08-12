<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class MonitorStorageUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:storage-usage 
                            {--tenant= : Check specific tenant by ID or slug}
                            {--fix : Fix inconsistencies in storage calculations}
                            {--alert : Send alerts for tenants over 90% usage}
                            {--orphans : Find and optionally delete orphaned files}
                            {--format=table : Output format (table, json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor and verify storage usage across all tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Analyzing storage usage...');

        // Check for orphaned files first if requested
        if ($this->option('orphans')) {
            $this->handleOrphanedFiles();

            return Command::SUCCESS;
        }

        // Get tenants to check
        $query = Tenant::query();

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId)->orWhere('slug', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return Command::SUCCESS;
        }

        $data = [];
        $alertTenants = [];
        $inconsistencies = [];

        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        foreach ($tenants as $tenant) {
            $bar->setMessage("Checking tenant: {$tenant->name}");

            // Calculate database storage
            $dbStorage = $tenant->documents()->sum('size');

            // Calculate actual filesystem storage
            $actualStorage = $this->calculateActualStorage($tenant);

            // Check for inconsistencies
            $difference = abs($dbStorage - $actualStorage);
            $percentDiff = $dbStorage > 0 ? ($difference / $dbStorage) * 100 : 0;

            if ($percentDiff > 5) { // More than 5% difference
                $inconsistencies[] = [
                    'tenant' => $tenant,
                    'db_storage' => $dbStorage,
                    'actual_storage' => $actualStorage,
                    'difference' => $difference,
                ];
            }

            // Calculate usage percentage
            $maxStorage = $tenant->max_storage_gb * 1024 * 1024 * 1024;
            $usagePercent = $maxStorage > 0 ? ($dbStorage / $maxStorage) * 100 : 0;

            // Check if over 90% and needs alert
            if ($usagePercent >= 90 && $this->option('alert')) {
                $alertTenants[] = $tenant;
            }

            $data[] = [
                'ID' => $tenant->id,
                'Name' => $tenant->name,
                'DB Storage' => $this->formatBytes($dbStorage),
                'Actual Storage' => $this->formatBytes($actualStorage),
                'Limit' => $tenant->max_storage_gb . ' GB',
                'Usage %' => round($usagePercent, 2) . '%',
                'Status' => $this->getStorageStatus($usagePercent),
                'Difference' => $percentDiff > 5 ? $this->formatBytes($difference) . ' ⚠️' : 'OK',
            ];

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Output results
        $this->outputResults($data);

        // Show inconsistencies
        if (! empty($inconsistencies)) {
            $this->newLine();
            $this->warn('⚠️  Storage Inconsistencies Detected:');

            foreach ($inconsistencies as $inc) {
                $this->line(sprintf(
                    "  - %s: DB=%s, Actual=%s, Difference=%s",
                    $inc['tenant']->name,
                    $this->formatBytes($inc['db_storage']),
                    $this->formatBytes($inc['actual_storage']),
                    $this->formatBytes($inc['difference'])
                ));
            }

            if ($this->option('fix')) {
                $this->newLine();
                if ($this->confirm('Do you want to fix these inconsistencies?')) {
                    $this->fixInconsistencies($inconsistencies);
                }
            }
        }

        // Send alerts
        if (! empty($alertTenants) && $this->option('alert')) {
            $this->sendStorageAlerts($alertTenants);
        }

        // Summary
        $this->newLine();
        $this->info('Storage Usage Summary:');
        $this->line('Total Tenants: ' . $tenants->count());
        $this->line('Total Storage Used: ' . $this->formatBytes($tenants->sum(function ($t) {
            return $t->documents()->sum('size');
        })));
        $this->line('Tenants Over 90%: ' . count($alertTenants));
        $this->line('Inconsistencies Found: ' . count($inconsistencies));

        return Command::SUCCESS;
    }

    /**
     * Calculate actual storage used on filesystem
     */
    private function calculateActualStorage(Tenant $tenant): int
    {
        $totalSize = 0;

        // Check documents directory
        $documentsPath = 'documents/' . $tenant->id;
        if (Storage::exists($documentsPath)) {
            $files = Storage::allFiles($documentsPath);
            foreach ($files as $file) {
                $totalSize += Storage::size($file);
            }
        }

        // Check conversions directory
        $conversionsPath = 'conversions/' . $tenant->id;
        if (Storage::exists($conversionsPath)) {
            $files = Storage::allFiles($conversionsPath);
            foreach ($files as $file) {
                $totalSize += Storage::size($file);
            }
        }

        // Check thumbnails directory
        $thumbnailsPath = 'thumbnails/' . $tenant->id;
        if (Storage::exists($thumbnailsPath)) {
            $files = Storage::allFiles($thumbnailsPath);
            foreach ($files as $file) {
                $totalSize += Storage::size($file);
            }
        }

        return $totalSize;
    }

    /**
     * Handle orphaned files
     */
    private function handleOrphanedFiles(): void
    {
        $this->info('Scanning for orphaned files...');

        $orphanedFiles = [];
        $totalOrphanedSize = 0;

        // Get all document paths from database
        $dbPaths = Document::pluck('stored_name')->toArray();

        // Scan storage directories
        $storagePaths = ['documents', 'conversions', 'thumbnails'];

        foreach ($storagePaths as $basePath) {
            if (Storage::exists($basePath)) {
                $files = Storage::allFiles($basePath);

                foreach ($files as $file) {
                    // Skip temp files
                    if (str_contains($file, '/temp/') || str_contains($file, '.tmp')) {
                        continue;
                    }

                    // Check if file exists in database
                    if (! in_array($file, $dbPaths)) {
                        $size = Storage::size($file);
                        $orphanedFiles[] = [
                            'path' => $file,
                            'size' => $size,
                            'modified' => Storage::lastModified($file),
                        ];
                        $totalOrphanedSize += $size;
                    }
                }
            }
        }

        if (empty($orphanedFiles)) {
            $this->info('✅ No orphaned files found.');

            return;
        }

        $this->warn('Found ' . count($orphanedFiles) . ' orphaned files totaling ' . $this->formatBytes($totalOrphanedSize));

        // Show first 10 orphaned files
        $this->table(
            ['File Path', 'Size', 'Last Modified'],
            array_map(function ($file) {
                return [
                    substr($file['path'], 0, 50) . (strlen($file['path']) > 50 ? '...' : ''),
                    $this->formatBytes($file['size']),
                    date('Y-m-d H:i:s', $file['modified']),
                ];
            }, array_slice($orphanedFiles, 0, 10))
        );

        if (count($orphanedFiles) > 10) {
            $this->line('... and ' . (count($orphanedFiles) - 10) . ' more files');
        }

        if ($this->confirm('Do you want to delete these orphaned files?')) {
            $bar = $this->output->createProgressBar(count($orphanedFiles));
            $bar->start();

            foreach ($orphanedFiles as $file) {
                Storage::delete($file['path']);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('✅ Deleted ' . count($orphanedFiles) . ' orphaned files, freed ' . $this->formatBytes($totalOrphanedSize));
        }
    }

    /**
     * Fix storage inconsistencies
     */
    private function fixInconsistencies(array $inconsistencies): void
    {
        $this->info('Fixing storage inconsistencies...');

        foreach ($inconsistencies as $inc) {
            $tenant = $inc['tenant'];
            $actualStorage = $inc['actual_storage'];

            // Recalculate document sizes
            $documents = Document::where('tenant_id', $tenant->id)->get();

            foreach ($documents as $document) {
                if (Storage::exists($document->stored_name)) {
                    $actualSize = Storage::size($document->stored_name);
                    if ($actualSize != $document->size) {
                        $document->size = $actualSize;
                        $document->save();
                        $this->line("  Updated size for document {$document->id}");
                    }
                }
            }

            $this->info("✅ Fixed storage calculations for tenant: {$tenant->name}");
        }
    }

    /**
     * Send storage alerts
     */
    private function sendStorageAlerts(array $tenants): void
    {
        $this->info('Sending storage alerts for ' . count($tenants) . ' tenants...');

        foreach ($tenants as $tenant) {
            // Log the alert
            activity()
                ->performedOn($tenant)
                ->withProperties([
                    'storage_used' => $tenant->getStorageUsage(),
                    'storage_limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
                    'usage_percent' => $tenant->getStorageUsagePercentage(),
                ])
                ->log('Storage usage alert: Over 90% capacity');

            // TODO: Send email notification to tenant admin
            // Mail::to($tenant->admin_email)->send(new StorageAlertMail($tenant));

            $this->line("  Alert logged for: {$tenant->name}");
        }
    }

    /**
     * Get storage status
     */
    private function getStorageStatus(float $percent): string
    {
        if ($percent >= 100) {
            return '<fg=red>EXCEEDED</>';
        } elseif ($percent >= 90) {
            return '<fg=red>Critical</>';
        } elseif ($percent >= 75) {
            return '<fg=yellow>Warning</>';
        } elseif ($percent >= 50) {
            return '<fg=cyan>Moderate</>';
        } else {
            return '<fg=green>Good</>';
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
                    ['ID', 'Name', 'DB Storage', 'Actual Storage', 'Limit', 'Usage %', 'Status', 'Difference'],
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
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . $units[$pow];
    }
}

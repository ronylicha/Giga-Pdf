<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OptimizePdfStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:optimize-storage {--tenant= : Specific tenant ID to optimize}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize PDF storage by removing orphaned files and updating size calculations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting storage optimization...');

        $tenantId = $this->option('tenant');

        // Remove orphaned files
        $this->removeOrphanedFiles($tenantId);

        // Update document sizes
        $this->updateDocumentSizes($tenantId);

        // Clean up empty directories
        $this->cleanupEmptyDirectories($tenantId);

        // Update tenant storage usage
        $this->updateTenantStorageUsage($tenantId);

        $this->info('Storage optimization completed successfully.');

        return Command::SUCCESS;
    }

    private function removeOrphanedFiles($tenantId = null)
    {
        $this->info('Checking for orphaned files...');

        $query = Document::query();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $storedNames = $query->pluck('stored_name')->toArray();

        $directories = $tenantId
            ? ['documents/' . $tenantId]
            : Storage::directories('documents');

        $orphanedCount = 0;

        foreach ($directories as $directory) {
            $files = Storage::files($directory);

            foreach ($files as $file) {
                if (! in_array($file, $storedNames)) {
                    Storage::delete($file);
                    $orphanedCount++;
                    $this->line("Deleted orphaned file: {$file}");
                }
            }
        }

        $this->info("Removed {$orphanedCount} orphaned file(s).");
    }

    private function updateDocumentSizes($tenantId = null)
    {
        $this->info('Updating document sizes...');

        $query = Document::query();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $updatedCount = 0;

        $query->chunk(100, function ($documents) use (&$updatedCount) {
            foreach ($documents as $document) {
                if (Storage::exists($document->stored_name)) {
                    $actualSize = Storage::size($document->stored_name);
                    if ($actualSize != $document->size) {
                        $document->update(['size' => $actualSize]);
                        $updatedCount++;
                    }
                }
            }
        });

        $this->info("Updated {$updatedCount} document size(s).");
    }

    private function cleanupEmptyDirectories($tenantId = null)
    {
        $this->info('Cleaning up empty directories...');

        $directories = $tenantId
            ? ['documents/' . $tenantId]
            : Storage::directories('documents');

        $removedCount = 0;

        foreach ($directories as $directory) {
            if (empty(Storage::files($directory)) && empty(Storage::directories($directory))) {
                Storage::deleteDirectory($directory);
                $removedCount++;
                $this->line("Removed empty directory: {$directory}");
            }
        }

        $this->info("Removed {$removedCount} empty directory(ies).");
    }

    private function updateTenantStorageUsage($tenantId = null)
    {
        $this->info('Updating tenant storage usage...');

        $query = Tenant::query();
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $query->chunk(10, function ($tenants) {
            foreach ($tenants as $tenant) {
                $totalSize = Document::where('tenant_id', $tenant->id)->sum('size');
                $totalSizeGB = round($totalSize / (1024 * 1024 * 1024), 2);

                $metadata = $tenant->metadata ?? [];
                $metadata['storage_used_gb'] = $totalSizeGB;
                $tenant->update(['metadata' => $metadata]);

                $this->line("Tenant {$tenant->name}: {$totalSizeGB} GB used");
            }
        });

        $this->info('Tenant storage usage updated.');
    }
}

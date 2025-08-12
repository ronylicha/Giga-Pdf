<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Document;
use App\Models\Conversion;
use App\Models\Share;
use App\Models\ActivityLog;
use App\Models\Invitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:delete 
                            {id : The ID or slug of the tenant to delete}
                            {--force : Skip confirmation prompt}
                            {--keep-files : Do not delete storage files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a tenant and all associated data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $identifier = $this->argument('id');
        
        // Find tenant by ID or slug
        $tenant = Tenant::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();
        
        if (!$tenant) {
            $this->error("Tenant with ID or slug '{$identifier}' not found.");
            return Command::FAILURE;
        }

        // Show tenant information
        $this->info('Tenant Information:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $tenant->id],
                ['Name', $tenant->name],
                ['Slug', $tenant->slug],
                ['Domain', $tenant->domain ?? 'N/A'],
                ['Users', $tenant->users()->count()],
                ['Documents', $tenant->documents()->count()],
                ['Storage Used', $this->formatBytes($tenant->getStorageUsage())],
                ['Created', $tenant->created_at->format('Y-m-d H:i:s')],
            ]
        );

        // Confirm deletion
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This action cannot be undone!');
            $this->warn('The following will be permanently deleted:');
            $this->line('  - All users and their data');
            $this->line('  - All documents and conversions');
            $this->line('  - All shares and invitations');
            $this->line('  - All activity logs');
            if (!$this->option('keep-files')) {
                $this->line('  - All storage files');
            }
            
            if (!$this->confirm('Are you sure you want to delete this tenant?')) {
                $this->info('Deletion cancelled.');
                return Command::SUCCESS;
            }
            
            // Double confirmation for safety
            $confirmName = $this->ask("Please type the tenant name '{$tenant->name}' to confirm");
            if ($confirmName !== $tenant->name) {
                $this->error('Tenant name does not match. Deletion cancelled.');
                return Command::FAILURE;
            }
        }

        $this->info('Starting tenant deletion...');
        $bar = $this->output->createProgressBar(8);
        $bar->start();

        try {
            DB::beginTransaction();

            // 1. Delete shares
            $bar->setMessage('Deleting shares...');
            $shareCount = Share::whereHas('document', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })->delete();
            $bar->advance();

            // 2. Delete conversions
            $bar->setMessage('Deleting conversions...');
            $conversionCount = Conversion::where('tenant_id', $tenant->id)->delete();
            $bar->advance();

            // 3. Delete documents
            $bar->setMessage('Deleting documents...');
            $documents = Document::where('tenant_id', $tenant->id)->get();
            $documentCount = $documents->count();
            
            // Delete storage files if not keeping
            if (!$this->option('keep-files')) {
                foreach ($documents as $document) {
                    if (Storage::exists($document->stored_name)) {
                        Storage::delete($document->stored_name);
                    }
                }
                
                // Delete tenant directories
                $tenantStoragePath = 'documents/' . $tenant->id;
                if (Storage::exists($tenantStoragePath)) {
                    Storage::deleteDirectory($tenantStoragePath);
                }
                
                $tenantConversionPath = 'conversions/' . $tenant->id;
                if (Storage::exists($tenantConversionPath)) {
                    Storage::deleteDirectory($tenantConversionPath);
                }
                
                $tenantThumbnailPath = 'thumbnails/' . $tenant->id;
                if (Storage::exists($tenantThumbnailPath)) {
                    Storage::deleteDirectory($tenantThumbnailPath);
                }
            }
            
            Document::where('tenant_id', $tenant->id)->delete();
            $bar->advance();

            // 4. Delete activity logs
            $bar->setMessage('Deleting activity logs...');
            $activityCount = ActivityLog::where('tenant_id', $tenant->id)->delete();
            $bar->advance();

            // 5. Delete invitations
            $bar->setMessage('Deleting invitations...');
            $invitationCount = Invitation::where('tenant_id', $tenant->id)->delete();
            $bar->advance();

            // 6. Delete users
            $bar->setMessage('Deleting users...');
            $userCount = User::where('tenant_id', $tenant->id)->count();
            
            // Remove user roles and permissions
            $users = User::where('tenant_id', $tenant->id)->get();
            foreach ($users as $user) {
                $user->roles()->detach();
                $user->permissions()->detach();
            }
            
            User::where('tenant_id', $tenant->id)->delete();
            $bar->advance();

            // 7. Delete tenant-specific roles and permissions (if using teams)
            $bar->setMessage('Cleaning up roles and permissions...');
            DB::table('roles')->where('team_id', $tenant->id)->delete();
            DB::table('permissions')->where('team_id', $tenant->id)->delete();
            $bar->advance();

            // 8. Delete the tenant
            $bar->setMessage('Deleting tenant record...');
            $tenant->delete();
            $bar->advance();

            DB::commit();
            
            $bar->finish();
            $this->newLine(2);
            
            $this->info('✅ Tenant deleted successfully!');
            $this->line('Summary:');
            $this->line("  - Users deleted: {$userCount}");
            $this->line("  - Documents deleted: {$documentCount}");
            $this->line("  - Conversions deleted: {$conversionCount}");
            $this->line("  - Shares deleted: {$shareCount}");
            $this->line("  - Activity logs deleted: {$activityCount}");
            $this->line("  - Invitations deleted: {$invitationCount}");
            
            if (!$this->option('keep-files')) {
                $this->line("  - Storage files: Deleted");
            } else {
                $this->warn("  - Storage files: Retained (cleanup manually if needed)");
            }

            // Log the deletion
            activity()
                ->withProperties([
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'deleted_by' => 'console',
                    'keep_files' => $this->option('keep-files'),
                ])
                ->log('Tenant deleted via console command');

            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            
            $this->error('❌ Failed to delete tenant: ' . $e->getMessage());
            $this->error('All changes have been rolled back.');
            
            return Command::FAILURE;
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
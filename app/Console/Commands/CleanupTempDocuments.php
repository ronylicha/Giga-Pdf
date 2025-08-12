<?php

namespace App\Console\Commands;

use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupTempDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:cleanup-temp {--hours=24 : Hours after which temp documents should be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary documents older than specified hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $cutoffTime = Carbon::now()->subHours($hours);

        $this->info("Cleaning up temporary documents older than {$hours} hours...");

        // Find temp documents older than cutoff time
        $tempDocuments = Document::where('metadata->is_temp', true)
            ->where('created_at', '<', $cutoffTime)
            ->get();

        $count = 0;
        foreach ($tempDocuments as $tempDoc) {
            try {
                // Delete the file
                if (Storage::exists($tempDoc->stored_name)) {
                    Storage::delete($tempDoc->stored_name);
                }

                // Delete the database record
                $tempDoc->delete();
                $count++;

                $this->info("Deleted temp document: {$tempDoc->id}");
            } catch (\Exception $e) {
                $this->error("Failed to delete temp document {$tempDoc->id}: " . $e->getMessage());
            }
        }

        $this->info("Cleaned up {$count} temporary documents.");

        // Also clean up orphaned temp files
        $this->cleanupOrphanedFiles();

        return Command::SUCCESS;
    }

    /**
     * Clean up orphaned temp files that don't have database records
     */
    private function cleanupOrphanedFiles()
    {
        $this->info("Checking for orphaned temp files...");

        $tempPath = 'temp';
        if (! Storage::exists($tempPath)) {
            return;
        }

        $files = Storage::allFiles($tempPath);
        $orphanedCount = 0;

        foreach ($files as $file) {
            // Check if there's a document record for this file
            $exists = Document::where('stored_name', $file)->exists();

            if (! $exists) {
                // Check file age
                $lastModified = Storage::lastModified($file);
                $fileAge = Carbon::createFromTimestamp($lastModified);

                if ($fileAge->isBefore(Carbon::now()->subHours(24))) {
                    Storage::delete($file);
                    $orphanedCount++;
                    $this->info("Deleted orphaned file: {$file}");
                }
            }
        }

        if ($orphanedCount > 0) {
            $this->info("Cleaned up {$orphanedCount} orphaned files.");
        }
    }
}
